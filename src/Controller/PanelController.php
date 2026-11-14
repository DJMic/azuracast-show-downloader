<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Controller;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Flysystem\StationFilesystems;
use App\Http\Response;
use App\Http\ServerRequest;
use DateTimeImmutable;
use InvalidArgumentException;
use League\Flysystem\StorageAttributes;
use Plugin\AzuracastShowDownloader\Service\Job;
use Plugin\AzuracastShowDownloader\Service\Naming;
use Plugin\AzuracastShowDownloader\Service\Plugin;
use Plugin\AzuracastShowDownloader\Service\Runner;
use Plugin\AzuracastShowDownloader\Service\Scheduler;
use Plugin\AzuracastShowDownloader\Service\Store;
use Plugin\AzuracastShowDownloader\Service\Transfer;
use Plugin\AzuracastShowDownloader\Source\SourceException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class PanelController
{
    public const string CSRF_NAMESPACE = 'show_downloader';

    /** A run older than this is considered interrupted (AzuraCast long task timeout). */
    private const int RUNNING_TIMEOUT = 1900;

    public function __construct(
        private readonly Store $store,
        private readonly Runner $runner,
        private readonly StationFilesystems $filesystems,
        private readonly ReloadableEntityManagerInterface $em
    ) {
    }

    public function assetAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $name = (string)($params['name'] ?? '');
        $path = match ($name) {
            'navigation.js' => Plugin::dir() . '/frontend/navigation.js',
            'app.js', 'app.css' => Plugin::dir() . '/dist/' . $name,
            default => null,
        };
        if ($path === null) {
            return $response->withStatus(404);
        }
        if (!is_file($path)) {
            $response->getBody()->write(
                'Interface du plugin non compilée. Exécutez bin/setup.sh dans le dossier du plugin.'
            );

            return $response->withStatus(503)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $response->getBody()->write((string)file_get_contents($path));

        return $response
            ->withHeader('Content-Type', str_ends_with($name, '.css') ? 'text/css' : 'text/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function stateAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $station = $request->getStation();
            $data = $this->store->read($station);
            $now = new DateTimeImmutable('now', $station->getTimezoneObject());

            $jobs = [];
            foreach ($data['jobs'] as $job) {
                $jobs[] = Job::forDisplay($job) + ['status' => $this->status($job, $data['state'][$job['id']] ?? [], $now)];
            }

            return $this->json($response, [
                'version' => Plugin::version(),
                'csrf' => $request->getCsrf()->generate(self::CSRF_NAMESPACE),
                'station' => [
                    'id' => $station->id,
                    'name' => $station->name,
                    'timezone' => $station->timezone,
                    'now' => $now->format(DATE_ATOM),
                ],
                'jobs' => $jobs,
                'playlists' => $this->playlists($station),
            ]);
        });
    }

    public function foldersAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $station = $request->getStation();
            $dir = Naming::normalizeDirectory((string)$request->getParam('dir', ''));
            $fs = $this->filesystems->getMediaFilesystem($station);

            $rows = [];
            foreach ($fs->listContents($dir, false) as $item) {
                /** @var StorageAttributes $item */
                $base = basename($item->path());
                if (!$item->isDir() || str_starts_with($base, '.')) {
                    continue;
                }
                $rows[] = ['name' => $base, 'path' => $item->path()];
            }
            usort($rows, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

            return $this->json($response, ['dir' => $dir, 'folders' => $rows]);
        });
    }

    public function historyAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $station = $request->getStation();
            $jobId = (string)$request->getParam('job', '');
            $history = array_reverse($this->store->read($station)['history']);
            if ($jobId !== '') {
                $history = array_values(array_filter($history, static fn(array $h): bool => $h['job'] === $jobId));
            }

            return $this->json($response, ['rows' => array_slice($history, 0, 200)]);
        });
    }

    public function saveAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            $input = $this->body($request);
            $this->checkPlaylist($station, $input['target']['playlist_id'] ?? null);

            $saved = null;
            $this->store->update($station, static function (array $data) use ($input, &$saved): array {
                $id = (string)($input['id'] ?? '');
                $index = $id !== '' ? Store::findIndex($data['jobs'], $id) : null;
                if ($id !== '' && $index === null) {
                    throw new InvalidArgumentException('Cette émission n\'existe plus. Rechargez la page.');
                }
                $existing = $index === null ? null : $data['jobs'][$index];
                $job = Job::normalize($input, $existing);

                $data['state'][$job['id']] = Job::stateAfterSave($data['state'][$job['id']] ?? [], $job, $existing);

                if ($index === null) {
                    $data['jobs'][] = $job;
                } else {
                    $data['jobs'][$index] = $job;
                }
                $saved = $job;

                return $data;
            });

            return $this->json($response, ['success' => true, 'job' => Job::forDisplay($saved)]);
        });
    }

    public function deleteAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response, $params): ResponseInterface {
            $this->verifyCsrf($request);
            $id = (string)($params['job_id'] ?? '');
            $this->store->update($request->getStation(), static function (array $data) use ($id): array {
                $index = Store::findIndex($data['jobs'], $id);
                if ($index !== null) {
                    array_splice($data['jobs'], $index, 1);
                }
                unset($data['state'][$id]);

                return $data;
            });

            return $this->json($response, ['success' => true]);
        });
    }

    public function runAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response, $params): ResponseInterface {
            $this->verifyCsrf($request);
            $id = (string)($params['job_id'] ?? '');
            $this->store->update($request->getStation(), static function (array $data) use ($id): array {
                if (Store::findIndex($data['jobs'], $id) === null) {
                    throw new InvalidArgumentException('Cette émission n\'existe plus. Rechargez la page.');
                }
                $data['state'][$id]['run_requested'] = time();

                return $data;
            });

            return $this->json($response, [
                'success' => true,
                'message' => 'Téléchargement demandé : il démarrera dans la minute.',
            ]);
        });
    }

    public function testAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            @set_time_limit(120);
            $station = $request->getStation();
            $input = $this->body($request);

            $existing = null;
            $id = (string)($input['id'] ?? '');
            if ($id !== '') {
                $jobs = $this->store->read($station)['jobs'];
                $index = Store::findIndex($jobs, $id);
                $existing = $index === null ? null : $jobs[$index];
            }
            $job = Job::normalize($input, $existing);
            $now = new DateTimeImmutable('now', $station->getTimezoneObject());

            try {
                $result = $this->runner->test($station, $job, $now);
            } catch (SourceException $e) {
                $result = ['success' => false, 'message' => $e->getMessage(), 'files' => [], 'total' => 0];
            }
            $result['date'] = Runner::nameDate($job, $now)->format('Y-m-d');

            return $this->json($response, $result);
        });
    }

    public function clearHistoryAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $this->store->update($request->getStation(), static function (array $data): array {
                $data['history'] = [];

                return $data;
            });

            return $this->json($response, ['success' => true]);
        });
    }

    public function exportAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $station = $request->getStation();
            $withSecrets = filter_var($request->getParam('secrets', '0'), FILTER_VALIDATE_BOOLEAN);

            $names = [];
            foreach ($this->playlists($station) as $playlist) {
                $names[$playlist['id']] = $playlist['name'];
            }
            $export = Transfer::export($this->store->read($station)['jobs'], $names, $withSecrets, [
                'name' => $station->name,
                'short_name' => $station->short_name,
                'timezone' => $station->timezone,
            ]);

            $filename = sprintf(
                'telechargements-auto-%s-%s.json',
                preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$station->short_name) ?: 'station',
                date('Y-m-d')
            );
            $response->getBody()->write((string)json_encode(
                $export,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));

            return $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Cache-Control', 'no-store');
        });
    }

    public function importPreviewAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            [$payload, $choices] = $this->importBody($request);
            $fs = $this->filesystems->getMediaFilesystem($station);

            $preview = Transfer::analyze(
                $payload,
                $this->store->read($station)['jobs'],
                $this->playlists($station),
                static function (string $dir) use ($fs): ?bool {
                    try {
                        return $fs->directoryExists($dir);
                    } catch (Throwable) {
                        return null;
                    }
                },
                $choices,
                (string)$station->timezone
            );

            return $this->json($response, ['success' => true] + $preview);
        });
    }

    public function importAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->guard($response, function () use ($request, $response): ResponseInterface {
            $this->verifyCsrf($request);
            $station = $request->getStation();
            [$payload, $choices] = $this->importBody($request);
            $createDirectories = filter_var($this->body($request)['create_directories'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $playlists = $this->playlists($station);

            $summary = null;
            $this->store->update($station, static function (array $data) use ($payload, $choices, $playlists, &$summary): array {
                $result = Transfer::apply($payload, $data, $choices, $playlists);
                $summary = $result['summary'];

                return $result['data'];
            });

            // Missing folders: created now if asked, otherwise on the first download.
            $created = [];
            $failed = [];
            if ($createDirectories) {
                $fs = $this->filesystems->getMediaFilesystem($station);
                foreach ($summary['directories'] as $dir) {
                    try {
                        if (!$fs->directoryExists($dir)) {
                            $fs->createDirectory($dir);
                            $created[] = $dir;
                        }
                    } catch (Throwable) {
                        $failed[] = $dir;
                    }
                }
            }

            $parts = [];
            if ($summary['added'] > 0) {
                $parts[] = $summary['added'] . ' émission(s) ajoutée(s)';
            }
            if ($summary['replaced'] > 0) {
                $parts[] = $summary['replaced'] . ' émission(s) remplacée(s)';
            }
            $message = 'Import terminé : ' . implode(', ', $parts) . '.';
            if ($created !== []) {
                $message .= ' Dossier(s) créé(s) : ' . implode(', ', array_map(static fn($d) => '/' . $d, $created)) . '.';
            }
            if ($failed !== []) {
                $message .= ' Dossier(s) non créé(s), ils le seront au premier téléchargement : '
                    . implode(', ', array_map(static fn($d) => '/' . $d, $failed)) . '.';
            }
            if ($summary['disabled'] !== []) {
                $message .= ' Désactivée(s) en attendant le mot de passe ou la clé : '
                    . implode(', ', $summary['disabled']) . '. Modifiez-les pour saisir le mot de passe puis réactivez-les.';
            }

            return $this->json($response, ['success' => true, 'message' => $message, 'summary' => $summary]);
        });
    }

    /**
     * @return array{0: array, 1: array<int, array>}
     */
    private function importBody(ServerRequest $request): array
    {
        if ($request->getBody()->getSize() > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Fichier trop volumineux (5 Mo au maximum).');
        }
        $body = $this->body($request);
        $payload = $body['data'] ?? null;
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Fichier d\'import illisible.');
        }
        $choices = [];
        foreach (is_array($body['choices'] ?? null) ? $body['choices'] : [] as $index => $choice) {
            if (is_array($choice)) {
                $choices[(int)$index] = $choice;
            }
        }

        return [$payload, $choices];
    }

    private function status(array $job, array $state, DateTimeImmutable $now): array
    {
        $tz = $now->getTimezone();
        $format = static fn(?int $ts): ?string => $ts ? (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format(DATE_ATOM) : null;
        $next = $job['enabled'] ? Scheduler::nextSlot($job['schedule']['slots'], $now) : null;
        $running = isset($state['running_since']) && $state['running_since'] > time() - self::RUNNING_TIMEOUT;

        return [
            'running' => $running,
            'run_requested' => !empty($state['run_requested']),
            'last_run' => $format($state['last_run'] ?? null),
            'last_status' => $state['last_status'] ?? null,
            'last_message' => $state['last_message'] ?? null,
            'retry_at' => $format($state['retry_at'] ?? null),
            'attempts' => (int)($state['attempts'] ?? 0),
            'next_run' => $next?->format(DATE_ATOM),
        ];
    }

    private function playlists(Station $station): array
    {
        $playlists = $this->em->getRepository(StationPlaylist::class)->findBy(['station' => $station], ['name' => 'ASC']);
        $rows = [];
        foreach ($playlists as $playlist) {
            /** @var StationPlaylist $playlist */
            if ($playlist->source !== PlaylistSources::Songs) {
                continue;
            }
            $rows[] = ['id' => $playlist->id, 'name' => $playlist->name, 'enabled' => $playlist->is_enabled];
        }

        return $rows;
    }

    private function checkPlaylist(Station $station, mixed $playlistId): void
    {
        if ($playlistId === null || $playlistId === '' || (int)$playlistId <= 0) {
            return;
        }
        $playlist = $this->em->find(StationPlaylist::class, (int)$playlistId);
        if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $station->id) {
            throw new InvalidArgumentException('Playlist introuvable pour cette station.');
        }
        if ($playlist->source !== PlaylistSources::Songs) {
            throw new InvalidArgumentException('Cette playlist ne peut pas contenir de fichiers.');
        }
    }

    private function verifyCsrf(ServerRequest $request): void
    {
        try {
            $request->getCsrf()->verify($request->getHeaderLine('X-CSRF-Token'), self::CSRF_NAMESPACE);
        } catch (Throwable) {
            throw new InvalidArgumentException('Session expirée : rechargez la page puis recommencez.');
        }
    }

    private function body(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = json_decode((string)$request->getBody(), true);
        }

        return is_array($body) ? $body : [];
    }

    private function json(Response $response, array $data, int $status = 200): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store')->withJson($data, $status);
    }

    /**
     * @param callable(): ResponseInterface $action
     */
    private function guard(Response $response, callable $action): ResponseInterface
    {
        try {
            return $action();
        } catch (InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
