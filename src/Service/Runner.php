<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Service;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Flysystem\StationFilesystems;
use App\Media\MediaProcessor;
use DateTimeImmutable;
use Plugin\AzuracastShowDownloader\Source\DirectorySourceInterface;
use Plugin\AzuracastShowDownloader\Source\FtpSource;
use Plugin\AzuracastShowDownloader\Source\HttpSource;
use Plugin\AzuracastShowDownloader\Source\RemoteFile;
use Plugin\AzuracastShowDownloader\Source\SftpSource;
use Plugin\AzuracastShowDownloader\Source\SourceException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Downloads the file(s) of one job and imports them into the station media library.
 */
final class Runner
{
    public const int MAX_FILES = 50;

    public function __construct(
        private readonly StationFilesystems $filesystems,
        private readonly MediaProcessor $mediaProcessor,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly StationPlaylistMediaRepository $playlistMediaRepo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Broadcast date used in names: reference day + offset (offset only in "date in file name" mode).
     */
    public static function nameDate(array $job, DateTimeImmutable $ref): DateTimeImmutable
    {
        $offset = !empty($job['file']['date_enabled']) ? (int)$job['file']['date_offset'] : 0;

        return $offset === 0 ? $ref : $ref->modify(($offset > 0 ? '+' : '') . $offset . ' days');
    }

    public static function remotePattern(array $job, DateTimeImmutable $date): string
    {
        $pattern = (string)$job['file']['pattern'];

        return !empty($job['file']['date_enabled'])
            ? Naming::render($pattern, [], $date, (string)$job['file']['date_format'])
            : $pattern;
    }

    public static function remoteUrl(array $job, DateTimeImmutable $date): string
    {
        $url = (string)$job['source']['url'];
        if (empty($job['file']['date_enabled'])) {
            return $url;
        }

        return (string)preg_replace_callback(
            '/\{date(?::([^}]*))?\}/',
            static fn(array $m): string => rawurlencode(Naming::formatDate(
                isset($m[1]) && $m[1] !== '' ? $m[1] : (string)$job['file']['date_format'],
                $date
            )),
            $url
        );
    }

    public static function targetPath(array $job, string $original, int $index, DateTimeImmutable $date): string
    {
        $name = Naming::targetName(
            $original,
            (bool)$job['target']['rename_enabled'],
            (string)$job['target']['rename_pattern'],
            (string)$job['name'],
            $index,
            $date,
            (string)$job['file']['date_format']
        );
        $dir = (string)$job['target']['directory'];

        return ($dir === '' ? '' : $dir . '/') . $name;
    }

    /**
     * Connects and shows what would be downloaded, without downloading anything.
     */
    public function test(Station $station, array $job, DateTimeImmutable $ref): array
    {
        $date = self::nameDate($job, $ref);

        if ($job['source']['type'] === 'url') {
            $url = self::remoteUrl($job, $date);
            $info = (new HttpSource($job['source']))->probe($url);

            return [
                'success' => true,
                'message' => 'Le fichier est accessible.',
                'source' => $url,
                'files' => [[
                    'name' => $info['name'],
                    'size' => $info['size'],
                    'mtime' => $info['mtime'],
                    'selected' => true,
                    'target' => self::targetPath($job, $info['name'], 1, $date),
                ]],
                'total' => 1,
            ];
        }

        $client = $this->directoryClient($job);
        try {
            $pattern = self::remotePattern($job, $date);
            $all = $client->listFiles();
            [$matched, $selected] = $this->select($client, $job, $all, $pattern);

            $selectedNames = array_map(static fn(RemoteFile $f): string => $f->name, $selected);
            $files = [];
            $i = 0;
            foreach ($matched as $file) {
                $isSelected = in_array($file->name, $selectedNames, true);
                $files[] = $file->toArray() + [
                    'selected' => $isSelected,
                    'target' => $isSelected ? self::targetPath($job, $file->name, ++$i, $date) : null,
                ];
            }

            return [
                'success' => $selected !== [],
                'message' => $selected !== []
                    ? sprintf('Connexion réussie. %d fichier(s) correspondent au modèle « %s ».', count($matched), $pattern)
                    : sprintf(
                        'Connexion réussie, mais aucun fichier ne correspond au modèle « %s » (%d fichier(s) dans le répertoire).',
                        $pattern,
                        count($all)
                    ),
                'source' => $pattern,
                'files' => array_slice($files, 0, 100),
                'available' => $selected === []
                    ? array_slice(array_map(static fn(RemoteFile $f): string => $f->name, $all), 0, 30)
                    : [],
                'total' => count($matched),
            ];
        } finally {
            $client->close();
        }
    }

    /**
     * @return array{status: string, message: string, files: string[], hash: ?string}
     *         status: success | skipped | unchanged | not_found | error
     */
    public function execute(
        Station $station,
        array $job,
        DateTimeImmutable $ref,
        ?string $lastHash,
        bool $checkUnchanged
    ): array {
        $date = self::nameDate($job, $ref);
        $tempDir = $station->getRadioTempDir() . '/show-downloader';
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            return self::result('error', 'Dossier temporaire inaccessible : ' . $tempDir);
        }

        /** @var array<int, array{original: string, temp: string}> $downloads */
        $downloads = [];

        try {
            // 1. Download to the station's temporary directory.
            if ($job['source']['type'] === 'url') {
                $url = self::remoteUrl($job, $date);
                $temp = $this->tempPath($tempDir);
                $original = (new HttpSource($job['source']))->download($url, $temp);
                $downloads[] = ['original' => $original, 'temp' => $temp];
            } else {
                $client = $this->directoryClient($job);
                try {
                    $pattern = self::remotePattern($job, $date);
                    [, $selected] = $this->select($client, $job, $client->listFiles(), $pattern);
                    if ($selected === []) {
                        throw SourceException::notFound(sprintf(
                            'Aucun fichier ne correspond à « %s » dans %s.',
                            $pattern,
                            $job['source']['remote_dir'] === '' ? 'le répertoire de connexion' : $job['source']['remote_dir']
                        ));
                    }
                    foreach ($selected as $file) {
                        $temp = $this->tempPath($tempDir);
                        $downloads[] = ['original' => $file->name, 'temp' => $temp];
                        $client->download($file, $temp);
                    }
                } finally {
                    $client->close();
                }
            }

            // 2. Same content as last time? The new episode may not be online yet.
            $hashes = [];
            foreach ($downloads as $download) {
                if (!is_file($download['temp']) || filesize($download['temp']) === 0) {
                    throw new SourceException('Le fichier téléchargé « ' . $download['original'] . ' » est vide.');
                }
                $hashes[] = (string)sha1_file($download['temp']);
            }
            $hash = sha1(implode('|', $hashes));
            if ($checkUnchanged && !empty($job['schedule']['skip_unchanged']) && $lastHash !== null && $hash === $lastHash) {
                return self::result(
                    'unchanged',
                    'Le fichier distant est identique au dernier téléchargement : la nouvelle émission n\'est pas encore disponible.',
                    [],
                    $hash
                );
            }

            // 3. Import into the media library.
            $imported = [];
            $skipped = [];
            $warnings = [];
            $index = 0;
            $fs = $this->filesystems->getMediaFilesystem($station);
            $storageLocation = $station->media_storage_location;

            foreach ($downloads as $download) {
                $index++;
                $path = self::targetPath($job, $download['original'], $index, $date);

                if ($fs->fileExists($path)) {
                    if ($job['target']['on_exists'] === 'skip') {
                        $skipped[] = $path;
                        continue;
                    }
                    if ($job['target']['on_exists'] === 'rename') {
                        $path = $this->freePath($fs, $path);
                    }
                }

                $size = (int)filesize($download['temp']);
                if (!$storageLocation->canHoldFile($size)) {
                    throw new RuntimeException('Espace de stockage de la station insuffisant pour « ' . $path . ' ».');
                }

                $media = $this->mediaProcessor->processAndUpload($storageLocation, $path, $download['temp']);
                $imported[] = $path;

                if ($media instanceof StationMedia && !empty($job['target']['playlist_id'])) {
                    try {
                        $this->addToPlaylist($station, $media, (int)$job['target']['playlist_id']);
                    } catch (Throwable $e) {
                        $warnings[] = $e->getMessage();
                    }
                }
            }

            if ($imported === [] && $skipped !== []) {
                return self::result(
                    'skipped',
                    'Fichier déjà présent, non remplacé : ' . implode(', ', $skipped),
                    $skipped,
                    $hash
                );
            }

            $message = count($imported) === 1
                ? 'Émission importée : ' . $imported[0]
                : count($imported) . ' fichiers importés.';
            if ($skipped !== []) {
                $message .= ' Déjà présents (non remplacés) : ' . implode(', ', $skipped);
            }
            if ($warnings !== []) {
                $message .= ' Attention : ' . implode(' ', array_unique($warnings));
            }

            return self::result('success', $message, $imported, $hash);
        } catch (SourceException $e) {
            return self::result($e->notFound ? 'not_found' : 'error', $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('Show downloader: ' . $e->getMessage(), [
                'station' => $station->id,
                'job' => $job['id'] ?? null,
                'exception' => $e,
            ]);

            return self::result('error', $e->getMessage());
        } finally {
            foreach ($downloads as $download) {
                if (is_file($download['temp'])) {
                    @unlink($download['temp']);
                }
            }
        }
    }

    /**
     * @param RemoteFile[] $files
     *
     * @return array{0: RemoteFile[], 1: RemoteFile[]} [matched, selected]
     */
    private function select(DirectorySourceInterface $client, array $job, array $files, string $pattern): array
    {
        $matched = array_values(array_filter(
            $files,
            static fn(RemoteFile $f): bool => Naming::matches($pattern, $f->name)
        ));
        if ($matched === []) {
            return [[], []];
        }

        // Complete size/date (FTP listings only give names) and drop directories.
        if ($client instanceof FtpSource) {
            $matched = $client->statAll($matched);
        } else {
            $matched = array_map(static fn(RemoteFile $f): RemoteFile => $client->stat($f), $matched);
        }
        $matched = array_values(array_filter($matched, static fn(RemoteFile $f): bool => $f->size !== -1));

        usort($matched, static function (RemoteFile $a, RemoteFile $b): int {
            return [$b->mtime ?? 0, $b->name] <=> [$a->mtime ?? 0, $a->name];
        });

        if ($matched === []) {
            return [[], []];
        }

        if ($job['file']['select'] === 'all') {
            $selected = array_slice($matched, 0, self::MAX_FILES);
            usort($selected, static fn(RemoteFile $a, RemoteFile $b): int => strnatcasecmp($a->name, $b->name));

            return [$matched, $selected];
        }

        return [$matched, [$matched[0]]];
    }

    private function directoryClient(array $job): DirectorySourceInterface
    {
        return match ($job['source']['type']) {
            'sftp' => new SftpSource($job['source']),
            default => new FtpSource($job['source']),
        };
    }

    private function tempPath(string $dir): string
    {
        return $dir . '/' . bin2hex(random_bytes(8)) . '.part';
    }

    private function freePath(\League\Flysystem\FilesystemOperator $fs, string $path): string
    {
        [$base, $ext] = Naming::splitName($path);
        for ($i = 2; $i < 1000; $i++) {
            $candidate = $base . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
            if (!$fs->fileExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Impossible de trouver un nom libre pour ' . $path);
    }

    private function addToPlaylist(Station $station, StationMedia $media, int $playlistId): void
    {
        $playlist = $this->em->find(StationPlaylist::class, $playlistId);
        if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $station->id) {
            throw new RuntimeException('La playlist choisie n\'existe plus. Le fichier a été importé mais n\'est dans aucune playlist.');
        }
        if ($playlist->source !== PlaylistSources::Songs) {
            throw new RuntimeException('La playlist « ' . $playlist->name . ' » ne contient pas de fichiers (URL distante).');
        }

        $existing = $this->em->getRepository(StationPlaylistMedia::class)->findOneBy([
            'media' => $media,
            'playlist' => $playlist,
        ]);
        if ($existing === null) {
            $this->playlistMediaRepo->addMediaToPlaylist($media, $playlist);
            $this->em->flush();
        }
    }

    private static function result(string $status, string $message, array $files = [], ?string $hash = null): array
    {
        return ['status' => $status, 'message' => $message, 'files' => $files, 'hash' => $hash];
    }
}
