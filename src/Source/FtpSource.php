<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Source;

use CurlHandle;

/**
 * FTP / FTPS client based on PHP's cURL extension (always present in AzuraCast).
 */
final class FtpSource implements DirectorySourceInterface
{
    private const int MAX_STAT = 200;

    // libcurl error codes (not all are exposed as PHP constants).
    private const int E_COULDNT_RESOLVE_HOST = 6;
    private const int E_COULDNT_CONNECT = 7;
    private const int E_REMOTE_ACCESS_DENIED = 9;
    private const int E_FTP_COULDNT_RETR_FILE = 19;
    private const int E_OPERATION_TIMEDOUT = 28;
    private const int E_LOGIN_DENIED = 67;
    private const int E_REMOTE_FILE_NOT_FOUND = 78;
    private const int E_PARTIAL_FILE = 18;

    private ?CurlHandle $curl = null;

    public function __construct(
        private readonly array $source,
        private readonly int $connectTimeout = 20
    ) {
    }

    public function listFiles(): array
    {
        $body = $this->request($this->url(null), [
            CURLOPT_DIRLISTONLY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $files = [];
        foreach (preg_split('/\r?\n/', (string)$body) ?: [] as $line) {
            $name = basename(trim($line));
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $files[] = new RemoteFile($name);
        }

        return $files;
    }

    /**
     * Uses SIZE/MDTM. Returns a file with size -1 when the entry is a directory or unreadable.
     */
    public function stat(RemoteFile $file): RemoteFile
    {
        try {
            $this->request($this->url($file->name), [
                CURLOPT_NOBODY => true,
                CURLOPT_FILETIME => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
            ]);
        } catch (SourceException) {
            return new RemoteFile($file->name, -1, null);
        }
        $time = curl_getinfo($this->handle(), CURLINFO_FILETIME);
        $size = curl_getinfo($this->handle(), CURLINFO_CONTENT_LENGTH_DOWNLOAD);

        return new RemoteFile(
            $file->name,
            is_numeric($size) && $size >= 0 ? (int)$size : null,
            is_int($time) && $time > 0 ? $time : null
        );
    }

    public function statAll(array $files): array
    {
        $result = [];
        foreach (array_slice($files, 0, self::MAX_STAT) as $file) {
            $result[] = $this->stat($file);
        }

        return $result;
    }

    public function download(RemoteFile $file, string $localPath): void
    {
        $handle = fopen($localPath, 'wb');
        if ($handle === false) {
            throw new SourceException('Impossible de créer le fichier temporaire ' . $localPath);
        }

        try {
            $this->retrieve($file, [
                CURLOPT_FILE => $handle,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => 120,
            ], static function () use ($handle): void {
                ftruncate($handle, 0);
                rewind($handle);
            });
        } catch (SourceException $e) {
            fclose($handle);
            @unlink($localPath);
            throw $e;
        }
        fclose($handle);
    }

    /**
     * Reads the first byte of the file: proves that the server really lets this account download it.
     *
     * @return string|null Null when readable, otherwise the reason.
     */
    public function checkReadable(RemoteFile $file): ?string
    {
        try {
            $this->retrieve($file, [
                CURLOPT_RANGE => '0-0',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
            ]);
        } catch (SourceException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * RETR with fallbacks: some servers refuse the default "CWD into each folder, then RETR"
     * sequence, or refuse RETR on a connection that already sent many commands.
     */
    private function retrieve(RemoteFile $file, array $options, ?callable $beforeRetry = null): void
    {
        $attempts = [
            [CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_MULTICWD],
            [CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_NOCWD],
            [CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_SINGLECWD],
        ];
        $first = null;

        foreach ($attempts as $i => $method) {
            if ($i > 0) {
                $this->close(); // fresh connection
                if ($beforeRetry !== null) {
                    $beforeRetry();
                }
            }
            try {
                $this->request($this->url($file->name), $method + $options);

                return;
            } catch (SourceException $e) {
                $first ??= $e;
                if (!in_array($e->curlCode, [self::E_FTP_COULDNT_RETR_FILE, self::E_REMOTE_FILE_NOT_FOUND], true)) {
                    throw $e;
                }
            }
        }

        $reply = $first->serverReply !== '' ? $first->serverReply : 'RETR 550';
        $e = SourceException::notFound(sprintf(
            'Le serveur FTP refuse d\'envoyer « %s » alors que ce fichier apparaît dans le répertoire '
            . '(réponse du serveur : %s). Causes habituelles : le compte FTP n\'a pas le droit de lecture '
            . '(compte limité au dépôt de fichiers), ou le fichier est encore en cours d\'envoi sur le serveur. '
            . 'Vérifiez en téléchargeant ce fichier avec FileZilla et le même compte.',
            $file->name,
            $reply
        ));
        $e->serverReply = $first->serverReply;
        throw $e;
    }

    public function close(): void
    {
        $this->curl = null;
    }

    private function handle(): CurlHandle
    {
        if ($this->curl === null) {
            $curl = curl_init();
            if ($curl === false) {
                throw new SourceException('cURL n\'est pas disponible.');
            }
            $this->curl = $curl;
        }

        return $this->curl;
    }

    private function request(string $url, array $options): string|bool
    {
        $curl = $this->handle();
        curl_reset($curl);

        $encryption = $this->source['encryption'] ?? 'none';
        $verify = (bool)($this->source['verify_tls'] ?? true);

        $common = [
            CURLOPT_URL => $url,
            CURLOPT_USERNAME => (string)$this->source['username'],
            CURLOPT_PASSWORD => (string)$this->source['password'],
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS,
        ];
        if ($encryption === 'explicit') {
            $common[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
        }
        if (empty($this->source['passive'])) {
            $common[CURLOPT_FTPPORT] = '-';
        }

        // The protocol log is only used to quote the server's last error reply ("< 550 ...").
        $log = fopen('php://temp', 'w+b');
        if ($log !== false) {
            $common[CURLOPT_VERBOSE] = true;
            $common[CURLOPT_STDERR] = $log;
        }

        curl_setopt_array($curl, $common);
        curl_setopt_array($curl, $options);

        try {
            $result = curl_exec($curl);
            $errno = curl_errno($curl);

            // A partial read (range request for the readability check) is not an error.
            if ($result === false && !(isset($options[CURLOPT_RANGE]) && $errno === self::E_PARTIAL_FILE)) {
                $e = self::error($errno, curl_error($curl));
                $e->curlCode = $errno;
                $e->serverReply = $log !== false ? self::lastErrorReply($log) : '';
                throw $e;
            }
        } finally {
            if ($log !== false) {
                fclose($log);
            }
        }

        return $result === false ? '' : $result;
    }

    /**
     * @param resource $log
     */
    private static function lastErrorReply($log): string
    {
        rewind($log);
        $reply = '';
        while (($line = fgets($log)) !== false) {
            // Only server replies are kept; the commands sent (with the password) are ignored.
            if (preg_match('/^< ([45]\d\d[ -].*)$/', rtrim($line), $m)) {
                $reply = mb_substr(trim($m[1]), 0, 200);
            }
        }

        return $reply;
    }

    private static function error(int $errno, string $error): SourceException
    {
        return match ($errno) {
            self::E_REMOTE_FILE_NOT_FOUND, self::E_FTP_COULDNT_RETR_FILE => SourceException::notFound(
                'Fichier introuvable sur le serveur FTP (' . $error . ').'
            ),
            self::E_LOGIN_DENIED => new SourceException('Connexion FTP refusée : vérifiez l\'utilisateur et le mot de passe.'),
            self::E_REMOTE_ACCESS_DENIED => new SourceException(
                'Accès refusé au répertoire distant (il n\'existe pas ou les droits sont insuffisants).'
            ),
            self::E_COULDNT_RESOLVE_HOST => new SourceException('Nom d\'hôte introuvable.'),
            self::E_COULDNT_CONNECT, self::E_OPERATION_TIMEDOUT => new SourceException(
                'Connexion impossible au serveur FTP (' . $error . ').'
            ),
            default => new SourceException('Erreur FTP : ' . $error . ' (code ' . $errno . ').'),
        };
    }

    private function url(?string $file): string
    {
        $scheme = ($this->source['encryption'] ?? 'none') === 'implicit' ? 'ftps' : 'ftp';
        $host = (string)$this->source['host'];
        $port = (int)$this->source['port'];

        $dir = (string)($this->source['remote_dir'] ?? '');
        $path = '';
        if ($dir !== '') {
            $absolute = str_starts_with($dir, '/');
            $segments = array_filter(explode('/', $dir), static fn(string $s): bool => $s !== '');
            $path = implode('/', array_map('rawurlencode', $segments));
            $path = ($absolute ? '%2F' : '') . $path;
            if ($path !== '' && $path !== '%2F') {
                $path .= '/';
            }
        }
        $path .= $file === null ? '' : rawurlencode($file);

        return $scheme . '://' . $host . ':' . $port . '/' . $path;
    }
}
