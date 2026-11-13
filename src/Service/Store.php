<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Service;

use App\Entity\Station;
use JsonException;
use RuntimeException;

/**
 * Per-station storage in the station's configuration directory
 * (/var/azuracast/stations/<station>/config), which survives AzuraCast and plugin updates.
 *
 *   show-downloader.json          jobs (contains FTP/SFTP credentials, mode 0600)
 *   show-downloader-state.json    scheduling state per job
 *   show-downloader-history.json  last download results
 */
final class Store
{
    public const int HISTORY_LIMIT = 300;

    private const array FILES = [
        'jobs' => 'show-downloader.json',
        'state' => 'show-downloader-state.json',
        'history' => 'show-downloader-history.json',
    ];

    /**
     * @return array{jobs: array<int, array>, state: array<string, array>, history: array<int, array>}
     */
    public function read(Station $station): array
    {
        return $this->update($station, static fn(array $data): array => $data);
    }

    /**
     * Runs $callback under an exclusive lock and saves what changed.
     *
     * @param callable(array): array $callback
     *
     * @return array{jobs: array<int, array>, state: array<string, array>, history: array<int, array>}
     */
    public function update(Station $station, callable $callback): array
    {
        $dir = $station->getRadioConfigDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Dossier de configuration de la station introuvable : ' . $dir);
        }

        $lock = fopen($dir . '/show-downloader.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Impossible de verrouiller la configuration des téléchargements.');
        }

        try {
            $stored = [];
            foreach (self::FILES as $key => $file) {
                $stored[$key] = $this->load($dir . '/' . $file);
            }
            $stored['jobs'] = array_values($stored['jobs']);

            $updated = $callback($stored);

            if (count($updated['history'] ?? []) > self::HISTORY_LIMIT) {
                $updated['history'] = array_slice($updated['history'], -self::HISTORY_LIMIT);
            }

            foreach (self::FILES as $key => $file) {
                $value = $updated[$key] ?? [];
                if ($key === 'jobs') {
                    $value = array_values($value);
                }
                if ($value !== $stored[$key]) {
                    $this->save($dir, $dir . '/' . $file, $value);
                }
                $updated[$key] = $value;
            }

            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Lecture impossible : ' . $path);
        }
        if (trim($json) === '') {
            return [];
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Fichier JSON endommagé : ' . $path . ' (' . $e->getMessage() . ')');
        }

        return is_array($data) ? $data : [];
    }

    private function save(string $dir, string $path, array $data): void
    {
        $tmp = tempnam($dir, '.show-downloader-');
        if ($tmp === false) {
            throw new RuntimeException('Écriture impossible dans ' . $dir);
        }
        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($tmp, $json . "\n") === false) {
                throw new RuntimeException('Écriture impossible : ' . $path);
            }
            @chmod($tmp, 0600);
            if (!rename($tmp, $path)) {
                throw new RuntimeException('Écriture impossible : ' . $path);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @param array<int, array> $jobs
     */
    public static function findIndex(array $jobs, string $id): ?int
    {
        foreach ($jobs as $i => $job) {
            if (($job['id'] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }
}
