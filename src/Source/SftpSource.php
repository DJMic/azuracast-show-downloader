<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Source;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * SFTP client based on phpseclib 3 (shipped with AzuraCast).
 */
final class SftpSource implements DirectorySourceInterface
{
    private ?SFTP $sftp = null;

    public function __construct(
        private readonly array $source,
        private readonly int $connectTimeout = 20
    ) {
    }

    public function listFiles(): array
    {
        $sftp = $this->connect();
        $dir = $this->dir();
        $entries = $sftp->rawlist($dir);
        if (!is_array($entries)) {
            throw new SourceException('Répertoire distant introuvable ou illisible : ' . ($dir === '.' ? '(racine)' : $dir));
        }

        $files = [];
        foreach ($entries as $name => $attributes) {
            $name = (string)$name;
            if ($name === '.' || $name === '..') {
                continue;
            }
            $type = is_array($attributes) ? ($attributes['type'] ?? null) : null;
            if ($type !== null && $type !== 1 /* NET_SFTP_TYPE_REGULAR */ && $type !== 3 /* symlink */) {
                continue;
            }
            $files[] = new RemoteFile(
                $name,
                isset($attributes['size']) ? (int)$attributes['size'] : null,
                isset($attributes['mtime']) ? (int)$attributes['mtime'] : null
            );
        }

        return $files;
    }

    public function stat(RemoteFile $file): RemoteFile
    {
        if ($file->size !== null && $file->mtime !== null) {
            return $file;
        }
        $stat = $this->connect()->stat($this->path($file->name));
        if (!is_array($stat)) {
            return new RemoteFile($file->name, -1, null);
        }

        return new RemoteFile(
            $file->name,
            isset($stat['size']) ? (int)$stat['size'] : null,
            isset($stat['mtime']) ? (int)$stat['mtime'] : null
        );
    }

    public function download(RemoteFile $file, string $localPath): void
    {
        $sftp = $this->connect();
        $path = $this->path($file->name);
        if (!$sftp->file_exists($path)) {
            throw SourceException::notFound('Fichier introuvable sur le serveur SFTP : ' . $path);
        }
        $sftp->setTimeout(0);
        if ($sftp->get($path, $localPath) === false) {
            @unlink($localPath);
            throw new SourceException('Téléchargement SFTP impossible : ' . $path . ' (' . implode(' ', $sftp->getSFTPErrors()) . ')');
        }
    }

    public function close(): void
    {
        try {
            $this->sftp?->disconnect();
        } catch (Throwable) {
        }
        $this->sftp = null;
    }

    private function connect(): SFTP
    {
        if ($this->sftp !== null) {
            return $this->sftp;
        }

        try {
            $sftp = new SFTP((string)$this->source['host'], (int)$this->source['port'], $this->connectTimeout);
        } catch (Throwable $e) {
            throw new SourceException('Connexion SFTP impossible : ' . $e->getMessage());
        }

        $username = (string)$this->source['username'];
        $password = (string)$this->source['password'];
        $privateKey = (string)($this->source['private_key'] ?? '');

        try {
            if ($privateKey !== '') {
                $key = $password !== ''
                    ? PublicKeyLoader::load($privateKey, $password)
                    : PublicKeyLoader::load($privateKey);
                $ok = $sftp->login($username, $key);
            } else {
                $ok = $sftp->login($username, $password);
            }
        } catch (Throwable $e) {
            throw new SourceException('Connexion SFTP impossible : ' . $e->getMessage());
        }

        if (!$ok) {
            throw new SourceException('Connexion SFTP refusée : vérifiez l\'utilisateur, le mot de passe ou la clé privée.');
        }

        return $this->sftp = $sftp;
    }

    private function dir(): string
    {
        $dir = (string)($this->source['remote_dir'] ?? '');

        return $dir === '' ? '.' : $dir;
    }

    private function path(string $name): string
    {
        $dir = $this->dir();

        return ($dir === '.' ? '' : rtrim($dir, '/') . '/') . $name;
    }
}
