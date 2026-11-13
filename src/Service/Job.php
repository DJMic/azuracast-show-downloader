<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Service;

use InvalidArgumentException;

/**
 * Validates and normalizes a download job ("émission") as stored in the station's JSON file.
 *
 * Shape:
 * [
 *   id, name, enabled,
 *   source => [type: ftp|sftp|url, host, port, username, password, private_key,
 *              encryption: none|explicit|implicit, passive, verify_tls, remote_dir, url],
 *   file   => [pattern, select: newest|all, date_enabled, date_format, date_offset],
 *   target => [directory, rename_enabled, rename_pattern, on_exists: overwrite|skip|rename, playlist_id],
 *   tags   => [enabled, title, artist, album, genre]   (empty field = tag left unchanged),
 *   schedule => [slots: [[days: [1..7], time: "HH:MM"]], retry_interval, retry_count, skip_unchanged],
 * ]
 */
final class Job
{
    public const array SOURCE_TYPES = ['ftp', 'sftp', 'url'];
    public const array ENCRYPTIONS = ['none', 'explicit', 'implicit'];
    public const array SELECT_MODES = ['newest', 'all'];
    public const array ON_EXISTS = ['overwrite', 'skip', 'rename'];

    /**
     * @param array<string, mixed> $input Data posted by the editor.
     * @param array<string, mixed>|null $existing Stored job with the same id (keeps secrets).
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $input, ?array $existing): array
    {
        $source = self::arr($input['source'] ?? []);
        $file = self::arr($input['file'] ?? []);
        $target = self::arr($input['target'] ?? []);
        $schedule = self::arr($input['schedule'] ?? []);
        $tags = self::arr($input['tags'] ?? []);

        $name = self::str($input['name'] ?? '', 120);
        if ($name === '') {
            throw new InvalidArgumentException('Indiquez le nom de l\'émission.');
        }

        $type = self::oneOf($source['type'] ?? 'ftp', self::SOURCE_TYPES, 'Type de source inconnu.');

        $normalizedSource = [
            'type' => $type,
            'host' => '',
            'port' => 0,
            'username' => '',
            'password' => '',
            'private_key' => '',
            'encryption' => 'none',
            'passive' => true,
            'verify_tls' => true,
            'remote_dir' => '',
            'url' => '',
        ];

        // A null secret means "keep the stored value"; a string (even empty) replaces it.
        $oldSource = self::arr($existing['source'] ?? []);
        $password = array_key_exists('password', $source) && $source['password'] !== null
            ? self::str($source['password'], 1000, false)
            : (string)($oldSource['password'] ?? '');
        $privateKey = array_key_exists('private_key', $source) && $source['private_key'] !== null
            ? trim((string)$source['private_key'])
            : (string)($oldSource['private_key'] ?? '');

        if ($type === 'url') {
            $url = self::str($source['url'] ?? '', 2000);
            if (!preg_match('~^https?://~i', $url)) {
                throw new InvalidArgumentException('L\'URL doit commencer par http:// ou https://.');
            }
            $normalizedSource['url'] = $url;
            $normalizedSource['username'] = self::str($source['username'] ?? '', 200);
            $normalizedSource['password'] = $normalizedSource['username'] === '' ? '' : $password;
            $normalizedSource['verify_tls'] = self::bool($source['verify_tls'] ?? true);
        } else {
            $host = self::str($source['host'] ?? '', 255);
            $host = (string)preg_replace('~^(s?ftps?|https?)://~i', '', $host);
            $host = rtrim($host, '/');
            if ($host === '' || !preg_match('/^[A-Za-z0-9.\-_:\[\]]+$/', $host)) {
                throw new InvalidArgumentException('Nom d\'hôte invalide.');
            }
            $normalizedSource['host'] = $host;

            $encryption = $type === 'ftp'
                ? self::oneOf($source['encryption'] ?? 'none', self::ENCRYPTIONS, 'Chiffrement inconnu.')
                : 'none';
            $defaultPort = match (true) {
                $type === 'sftp' => 22,
                $encryption === 'implicit' => 990,
                default => 21,
            };
            $port = (int)($source['port'] ?? 0);
            $normalizedSource['port'] = ($port >= 1 && $port <= 65535) ? $port : $defaultPort;
            $normalizedSource['encryption'] = $encryption;
            $normalizedSource['passive'] = self::bool($source['passive'] ?? true);
            $normalizedSource['verify_tls'] = self::bool($source['verify_tls'] ?? true);
            $normalizedSource['username'] = self::str($source['username'] ?? '', 200);
            $normalizedSource['password'] = $password;
            $normalizedSource['private_key'] = $type === 'sftp' ? $privateKey : '';
            $normalizedSource['remote_dir'] = self::remoteDir(self::str($source['remote_dir'] ?? '', 500));

            if ($normalizedSource['username'] === '') {
                throw new InvalidArgumentException('Indiquez le nom d\'utilisateur (« anonymous » pour un FTP anonyme).');
            }
        }

        $dateEnabled = self::bool($file['date_enabled'] ?? false);
        $dateFormat = self::str($file['date_format'] ?? 'YYYYMMDD', 40);
        if ($dateEnabled && !preg_match('/YY|M|D/', $dateFormat)) {
            throw new InvalidArgumentException('Le format de date doit contenir au moins YYYY, YY, MM, M, DD ou D.');
        }
        $pattern = self::str($file['pattern'] ?? '', 255);
        if (str_contains($pattern, '/')) {
            throw new InvalidArgumentException('Le nom du fichier distant ne peut pas contenir « / ». Utilisez le répertoire distant.');
        }
        if ($type !== 'url' && $dateEnabled && !str_contains($pattern, '{date')) {
            throw new InvalidArgumentException('Ajoutez {date} dans le nom du fichier distant à l\'endroit où figure la date.');
        }
        if ($type === 'url' && $dateEnabled && !str_contains($normalizedSource['url'], '{date')) {
            throw new InvalidArgumentException('Ajoutez {date} dans l\'URL à l\'endroit où figure la date.');
        }

        $normalizedFile = [
            'pattern' => $type === 'url' ? '' : ($pattern === '' ? '*' : $pattern),
            'select' => self::oneOf($file['select'] ?? 'newest', self::SELECT_MODES, 'Mode de sélection inconnu.'),
            'date_enabled' => $dateEnabled,
            'date_format' => $dateFormat === '' ? 'YYYYMMDD' : $dateFormat,
            'date_offset' => max(-31, min(31, (int)($file['date_offset'] ?? 0))),
        ];
        if ($type === 'url') {
            $normalizedFile['select'] = 'newest';
        }

        $renameEnabled = self::bool($target['rename_enabled'] ?? false);
        $renamePattern = self::str($target['rename_pattern'] ?? '', 255);
        if ($renameEnabled && $renamePattern === '') {
            throw new InvalidArgumentException('Indiquez le nouveau nom du fichier ou décochez le renommage.');
        }
        if (str_contains($renamePattern, '/')) {
            throw new InvalidArgumentException('Le nouveau nom ne peut pas contenir « / ». Utilisez le dossier de destination.');
        }
        $playlistId = $target['playlist_id'] ?? null;

        $normalizedTarget = [
            'directory' => Naming::normalizeDirectory(self::str($target['directory'] ?? '', 500)),
            'rename_enabled' => $renameEnabled,
            'rename_pattern' => $renamePattern,
            'on_exists' => self::oneOf($target['on_exists'] ?? 'overwrite', self::ON_EXISTS, 'Option inconnue.'),
            'playlist_id' => ($playlistId === null || $playlistId === '' || (int)$playlistId <= 0)
                ? null
                : (int)$playlistId,
        ];

        $normalizedTags = ['enabled' => self::bool($tags['enabled'] ?? false)];
        foreach (Tagger::FIELDS as $field) {
            $normalizedTags[$field] = self::str($tags[$field] ?? '', 255);
        }
        if (
            $normalizedTags['enabled']
            && implode('', array_map(static fn(string $f): string => $normalizedTags[$f], Tagger::FIELDS)) === ''
        ) {
            throw new InvalidArgumentException('Remplissez au moins un tag (titre, artiste, album ou genre) ou décochez la modification des tags.');
        }

        $slots = [];
        foreach (self::arr($schedule['slots'] ?? []) as $slot) {
            $slot = self::arr($slot);
            $days = array_values(array_unique(array_filter(
                array_map('intval', self::arr($slot['days'] ?? [])),
                static fn(int $d): bool => $d >= 1 && $d <= 7
            )));
            sort($days);
            $time = self::str($slot['time'] ?? '', 5);
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Heure invalide dans la planification (format HH:MM).');
            }
            if ($days === []) {
                throw new InvalidArgumentException('Chaque horaire doit avoir au moins un jour coché.');
            }
            $slots[] = ['days' => $days, 'time' => $time];
        }

        $normalizedSchedule = [
            'slots' => array_slice($slots, 0, 50),
            'retry_interval' => max(5, min(720, (int)($schedule['retry_interval'] ?? 15))),
            'retry_count' => max(0, min(96, (int)($schedule['retry_count'] ?? 0))),
            'skip_unchanged' => self::bool($schedule['skip_unchanged'] ?? true),
        ];

        $id = (string)($existing['id'] ?? '');
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }

        return [
            'id' => $id,
            'name' => $name,
            'enabled' => self::bool($input['enabled'] ?? true),
            'source' => $normalizedSource,
            'file' => $normalizedFile,
            'target' => $normalizedTarget,
            'tags' => $normalizedTags,
            'schedule' => $normalizedSchedule,
            'created_at' => (int)($existing['created_at'] ?? time()),
            'updated_at' => time(),
        ];
    }

    /**
     * Job as sent to the browser: secrets are never returned.
     *
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    public static function forDisplay(array $job): array
    {
        $job['source']['has_password'] = ($job['source']['password'] ?? '') !== '';
        $job['source']['has_private_key'] = ($job['source']['private_key'] ?? '') !== '';
        $job['source']['password'] = null;
        $job['source']['private_key'] = null;

        return $job;
    }

    private static function remoteDir(string $dir): string
    {
        $dir = str_replace('\\', '/', $dir);
        if ($dir === '' || $dir === '/') {
            return '';
        }
        if (str_contains('/' . $dir . '/', '/../')) {
            throw new InvalidArgumentException('Le répertoire distant ne peut pas contenir "..".');
        }

        return rtrim($dir, '/');
    }

    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value, int $max, bool $trim = true): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = (string)$value;
        $value = str_replace("\0", '', $value);
        if ($trim) {
            $value = trim($value);
        }

        return mb_substr($value, 0, $max);
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function oneOf(mixed $value, array $allowed, string $error): string
    {
        $value = is_scalar($value) ? (string)$value : '';
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }
}
