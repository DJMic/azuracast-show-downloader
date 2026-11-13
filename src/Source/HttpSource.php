<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Source;

/**
 * Downloads a file from an http(s) URL with cURL.
 */
final class HttpSource
{
    private const array MIME_EXTENSIONS = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/aac' => 'aac',
        'audio/aacp' => 'aac',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/ogg' => 'ogg',
        'audio/opus' => 'opus',
        'audio/flac' => 'flac',
        'audio/x-flac' => 'flac',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
    ];

    public function __construct(
        private readonly array $source,
        private readonly int $connectTimeout = 20
    ) {
    }

    /**
     * Checks that the URL answers, without downloading the file.
     *
     * @return array{name: string, size: ?int, mtime: ?int, status: int}
     */
    public function probe(string $url): array
    {
        $headers = [];
        $curl = $this->init($url, $headers);
        curl_setopt_array($curl, [
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        // Some servers refuse HEAD: retry with a GET of the first byte.
        if ($ok === false || $status === 405 || $status === 403) {
            $headers = [];
            $curl = $this->init($url, $headers);
            curl_setopt_array($curl, [
                CURLOPT_RANGE => '0-0',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
            ]);
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        }
        if ($ok === false) {
            throw new SourceException('URL injoignable : ' . curl_error($curl));
        }
        $this->checkStatus($status, $url);

        $finalUrl = (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        $size = curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $range = self::header($headers, 'content-range');
        if ($range !== null && preg_match('~/(\d+)$~', $range, $m)) {
            $size = (int)$m[1];
        }
        $modified = self::header($headers, 'last-modified');

        return [
            'name' => $this->filename($headers, $finalUrl),
            'size' => is_numeric($size) && $size >= 0 ? (int)$size : null,
            'mtime' => $modified !== null ? (strtotime($modified) ?: null) : null,
            'status' => $status,
        ];
    }

    /**
     * @return string Original file name (from Content-Disposition or the URL).
     */
    public function download(string $url, string $localPath): string
    {
        $handle = fopen($localPath, 'wb');
        if ($handle === false) {
            throw new SourceException('Impossible de créer le fichier temporaire ' . $localPath);
        }

        $headers = [];
        $curl = $this->init($url, $headers);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 120,
        ]);
        $ok = curl_exec($curl);
        fclose($handle);

        if ($ok === false) {
            @unlink($localPath);
            throw new SourceException('Téléchargement impossible : ' . curl_error($curl));
        }
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        try {
            $this->checkStatus($status, $url);
        } catch (SourceException $e) {
            @unlink($localPath);
            throw $e;
        }

        $type = strtolower(trim(explode(';', (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]));
        if (str_starts_with($type, 'text/html')) {
            @unlink($localPath);
            throw new SourceException(
                'L\'URL renvoie une page web et non un fichier audio. Utilisez le lien direct vers le fichier.'
            );
        }

        $name = $this->filename($headers, (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL));
        if (pathinfo($name, PATHINFO_EXTENSION) === '' && isset(self::MIME_EXTENSIONS[$type])) {
            $name .= '.' . self::MIME_EXTENSIONS[$type];
        }

        return $name;
    }

    private function checkStatus(int $status, string $url): void
    {
        if ($status === 404 || $status === 410) {
            throw SourceException::notFound('Fichier introuvable à l\'adresse ' . $url . ' (HTTP ' . $status . ').');
        }
        if ($status === 401 || $status === 403) {
            throw new SourceException('Accès refusé par le serveur (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            throw new SourceException('Réponse inattendue du serveur (HTTP ' . $status . ').');
        }
    }

    /**
     * @param array<string, string> $headers Filled by reference while the request runs.
     */
    private function init(string $url, array &$headers): \CurlHandle
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new SourceException('cURL n\'est pas disponible.');
        }
        $verify = (bool)($this->source['verify_tls'] ?? true);
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'AzuraCast-ShowDownloader/1.0',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = []; // new response after a redirect
                } elseif (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }

                return strlen($line);
            },
        ];
        $username = (string)($this->source['username'] ?? '');
        if ($username !== '') {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
            $options[CURLOPT_USERNAME] = $username;
            $options[CURLOPT_PASSWORD] = (string)($this->source['password'] ?? '');
        }
        curl_setopt_array($curl, $options);

        return $curl;
    }

    private static function header(array $headers, string $name): ?string
    {
        return $headers[$name] ?? null;
    }

    private function filename(array $headers, string $finalUrl): string
    {
        $disposition = self::header($headers, 'content-disposition');
        if ($disposition !== null) {
            if (preg_match("/filename\\*\\s*=\\s*(?:UTF-8|utf-8)''([^;]+)/", $disposition, $m)) {
                return basename(rawurldecode(trim($m[1], " \"")));
            }
            if (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disposition, $m)) {
                return basename(trim($m[1]));
            }
        }
        $path = (string)parse_url($finalUrl, PHP_URL_PATH);
        $name = basename(rawurldecode($path));

        return $name !== '' ? $name : 'emission';
    }
}
