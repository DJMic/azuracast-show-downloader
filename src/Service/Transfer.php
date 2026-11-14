<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Service;

use InvalidArgumentException;
use Throwable;

/**
 * Export and import of a station's shows as a JSON file, to copy them to another station or
 * another AzuraCast instance.
 *
 * What depends on the instance is not exported as is:
 *   - the playlist is exported by name and matched by name on import;
 *   - the destination folder is checked on import and can be changed before importing;
 *   - passwords and SSH keys are only exported when explicitly asked.
 */
final class Transfer
{
    public const string FORMAT = 'azuracast-show-downloader';
    public const int FORMAT_VERSION = 1;
    public const int MAX_JOBS = 200;

    /**
     * @param array<int, array> $jobs
     * @param array<int, string> $playlistNames playlist id => name
     * @param array{name: string, short_name: string, timezone: string} $station
     */
    public static function export(array $jobs, array $playlistNames, bool $withSecrets, array $station): array
    {
        $exported = [];
        foreach ($jobs as $job) {
            $source = $job['source'];
            $source['has_password'] = ($source['password'] ?? '') !== '';
            $source['has_private_key'] = ($source['private_key'] ?? '') !== '';
            if (!$withSecrets) {
                $source['password'] = null;
                $source['private_key'] = null;
            }

            $target = $job['target'];
            $playlistId = $target['playlist_id'] ?? null;
            $target['playlist_name'] = $playlistId !== null ? ($playlistNames[(int)$playlistId] ?? null) : null;
            unset($target['playlist_id']);

            $exported[] = [
                'name' => $job['name'],
                'enabled' => $job['enabled'],
                'source' => $source,
                'file' => $job['file'],
                'target' => $target,
                'tags' => $job['tags'] ?? ['enabled' => false],
                'schedule' => $job['schedule'],
            ];
        }

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'plugin_version' => Plugin::version(),
            'exported_at' => gmdate('c'),
            'includes_secrets' => $withSecrets,
            'station' => $station,
            'jobs' => $exported,
        ];
    }

    /**
     * Checks the structure of an imported file and returns its shows.
     *
     * @return array<int, array>
     */
    public static function jobs(array $payload): array
    {
        if (($payload['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException(
                'Ce fichier n\'est pas un export de « Téléchargements auto ».'
            );
        }
        if ((int)($payload['format_version'] ?? 0) > self::FORMAT_VERSION) {
            throw new InvalidArgumentException(
                'Ce fichier a été créé par une version plus récente du plugin. Mettez d\'abord le plugin à jour.'
            );
        }
        $jobs = $payload['jobs'] ?? null;
        if (!is_array($jobs) || !array_is_list($jobs)) {
            throw new InvalidArgumentException('Le fichier ne contient pas de liste d\'émissions.');
        }
        if ($jobs === []) {
            throw new InvalidArgumentException('Le fichier ne contient aucune émission.');
        }
        if (count($jobs) > self::MAX_JOBS) {
            throw new InvalidArgumentException('Le fichier contient trop d\'émissions (' . self::MAX_JOBS . ' au maximum).');
        }
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                throw new InvalidArgumentException('Le fichier est endommagé (émission illisible).');
            }
        }

        return $jobs;
    }

    /**
     * Describes what the import will do, with the choices made so far.
     *
     * @param array<int, array> $existingJobs
     * @param array<int, array{id: int, name: string}> $playlists
     * @param callable(string): ?bool $directoryExists null when unknown
     * @param array<int, array> $choices index => [import, directory, playlist_id, mode]
     */
    public static function analyze(
        array $payload,
        array $existingJobs,
        array $playlists,
        callable $directoryExists,
        array $choices,
        string $stationTimezone
    ): array {
        $rows = [];
        foreach (self::jobs($payload) as $index => $job) {
            $choice = self::choice($job, $choices[$index] ?? [], $existingJobs, $playlists);
            $row = [
                'index' => $index,
                'name' => (string)($job['name'] ?? ''),
                'type' => (string)($job['source']['type'] ?? ''),
                'summary' => self::summary($job),
                'original_directory' => (string)($job['target']['directory'] ?? ''),
                'playlist_name' => $job['target']['playlist_name'] ?? null,
                'playlist_found' => $choice['playlist_id'] !== null
                    || ($job['target']['playlist_name'] ?? null) === null,
                'existing_id' => $choice['existing_id'],
                'missing_secrets' => self::missingSecrets($job),
                'choice' => $choice,
                'directory_exists' => null,
                'error' => null,
            ];

            try {
                $row['choice']['directory'] = Naming::normalizeDirectory($choice['directory']);
                Job::normalize(self::prepare($job, $row['choice']), null);
                $row['directory_exists'] = $row['choice']['directory'] === ''
                    ? true
                    : $directoryExists($row['choice']['directory']);
            } catch (InvalidArgumentException $e) {
                $row['error'] = $e->getMessage();
                $row['choice']['import'] = false;
            }
            $rows[] = $row;
        }

        $sourceTimezone = (string)($payload['station']['timezone'] ?? '');

        return [
            'station' => $payload['station'] ?? null,
            'exported_at' => $payload['exported_at'] ?? null,
            'includes_secrets' => !empty($payload['includes_secrets']),
            'timezone_warning' => $sourceTimezone !== '' && $sourceTimezone !== $stationTimezone
                ? sprintf(
                    'Les horaires ont été définis pour le fuseau « %s » ; cette station utilise « %s ». '
                    . 'Ils seront appliqués tels quels dans le fuseau de cette station.',
                    $sourceTimezone,
                    $stationTimezone
                )
                : null,
            'jobs' => $rows,
        ];
    }

    /**
     * Adds or replaces the chosen shows in the station data (all or nothing).
     *
     * @return array{data: array, summary: array{added: int, replaced: int, disabled: string[], directories: string[]}}
     */
    public static function apply(array $payload, array $data, array $choices, array $playlists): array
    {
        $summary = ['added' => 0, 'replaced' => 0, 'disabled' => [], 'directories' => []];

        foreach (self::jobs($payload) as $index => $job) {
            $choice = self::choice($job, $choices[$index] ?? [], $data['jobs'], $playlists);
            if (!$choice['import']) {
                continue;
            }

            $existingIndex = null;
            if ($choice['mode'] === 'replace' && $choice['existing_id'] !== null) {
                $existingIndex = Store::findIndex($data['jobs'], $choice['existing_id']);
            }
            $existing = $existingIndex === null ? null : $data['jobs'][$existingIndex];

            $input = self::prepare($job, $choice);
            if ($existing === null) {
                $input['name'] = self::uniqueName((string)$input['name'], $data['jobs']);
            }

            try {
                $new = Job::normalize($input, $existing);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('« ' . ($job['name'] ?? '?') . ' » : ' . $e->getMessage());
            }

            // A show without its password would fail at every slot: it is imported disabled.
            if ($new['enabled'] && self::missingSecrets($job) && $existing === null) {
                $new['enabled'] = false;
                $summary['disabled'][] = $new['name'];
            }

            $data['state'][$new['id']] = Job::stateAfterSave($data['state'][$new['id']] ?? [], $new, $existing);
            if ($existingIndex === null) {
                $data['jobs'][] = $new;
                $summary['added']++;
            } else {
                $data['jobs'][$existingIndex] = $new;
                $summary['replaced']++;
            }
            if ($new['target']['directory'] !== '') {
                $summary['directories'][] = $new['target']['directory'];
            }
        }

        if ($summary['added'] + $summary['replaced'] === 0) {
            throw new InvalidArgumentException('Aucune émission sélectionnée pour l\'import.');
        }
        $summary['directories'] = array_values(array_unique($summary['directories']));

        return ['data' => $data, 'summary' => $summary];
    }

    /**
     * Choices for one show, completed with defaults: same folder, playlist with the same name,
     * existing show with the same name replaced.
     */
    private static function choice(array $job, array $choice, array $existingJobs, array $playlists): array
    {
        $name = mb_strtolower(trim((string)($job['name'] ?? '')));
        $existingId = null;
        foreach ($existingJobs as $existing) {
            if (mb_strtolower(trim((string)$existing['name'])) === $name) {
                $existingId = (string)$existing['id'];
                break;
            }
        }

        if (array_key_exists('playlist_id', $choice)) {
            $playlistId = $choice['playlist_id'] === null || $choice['playlist_id'] === '' ? null : (int)$choice['playlist_id'];
            $valid = array_filter($playlists, static fn(array $p): bool => (int)$p['id'] === $playlistId);
            $playlistId = $valid === [] ? null : $playlistId;
        } else {
            $playlistId = null;
            $wanted = mb_strtolower(trim((string)($job['target']['playlist_name'] ?? '')));
            foreach ($playlists as $playlist) {
                if ($wanted !== '' && mb_strtolower(trim((string)$playlist['name'])) === $wanted) {
                    $playlistId = (int)$playlist['id'];
                    break;
                }
            }
        }

        $mode = ($choice['mode'] ?? 'replace') === 'add' ? 'add' : 'replace';

        return [
            'import' => array_key_exists('import', $choice) ? (bool)$choice['import'] : true,
            'directory' => is_string($choice['directory'] ?? null)
                ? $choice['directory']
                : (string)($job['target']['directory'] ?? ''),
            'playlist_id' => $playlistId,
            'mode' => $existingId === null ? 'add' : $mode,
            'existing_id' => $existingId,
        ];
    }

    private static function prepare(array $job, array $choice): array
    {
        $job['target'] = is_array($job['target'] ?? null) ? $job['target'] : [];
        $job['target']['directory'] = $choice['directory'];
        $job['target']['playlist_id'] = $choice['playlist_id'];
        unset($job['target']['playlist_name'], $job['id']);

        // null = "no secret in the file": keeps the password of a replaced show.
        $job['source'] = is_array($job['source'] ?? null) ? $job['source'] : [];
        foreach (['password', 'private_key'] as $secret) {
            if (!is_string($job['source'][$secret] ?? null)) {
                $job['source'][$secret] = null;
            }
        }
        unset($job['source']['has_password'], $job['source']['has_private_key']);

        return $job;
    }

    private static function missingSecrets(array $job): bool
    {
        $source = is_array($job['source'] ?? null) ? $job['source'] : [];
        $needed = (!empty($source['has_password']) && !is_string($source['password'] ?? null))
            || (!empty($source['has_private_key']) && !is_string($source['private_key'] ?? null));

        return $needed;
    }

    private static function uniqueName(string $name, array $jobs): string
    {
        $names = array_map(static fn(array $j): string => mb_strtolower((string)$j['name']), $jobs);
        if (!in_array(mb_strtolower($name), $names, true)) {
            return $name;
        }
        for ($i = 2; $i < 1000; $i++) {
            $candidate = mb_substr($name, 0, 110) . ' (' . $i . ')';
            if (!in_array(mb_strtolower($candidate), $names, true)) {
                return $candidate;
            }
        }

        return $name;
    }

    private static function summary(array $job): string
    {
        $source = is_array($job['source'] ?? null) ? $job['source'] : [];
        try {
            if (($source['type'] ?? '') === 'url') {
                return (string)($source['url'] ?? '');
            }
            $dir = trim((string)($source['remote_dir'] ?? ''), '/');

            return sprintf(
                '%s@%s/%s%s',
                (string)($source['username'] ?? ''),
                (string)($source['host'] ?? ''),
                $dir === '' ? '' : $dir . '/',
                (string)($job['file']['pattern'] ?? '')
            );
        } catch (Throwable) {
            return '';
        }
    }
}
