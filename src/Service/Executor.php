<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Service;

use App\Entity\Station;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks every job of a station and runs the ones that are due.
 * The store lock is never held while a file downloads.
 */
final class Executor
{
    public function __construct(
        private readonly Store $store,
        private readonly Runner $runner,
        private readonly LoggerInterface $logger
    ) {
    }

    public function processStation(Station $station, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable('now', $station->getTimezoneObject());
        $data = $this->store->read($station);

        foreach ($data['jobs'] as $job) {
            $id = (string)$job['id'];
            $state = $data['state'][$id] ?? [];
            $decision = Scheduler::decide($job, $state, $now);
            if ($decision === null) {
                continue;
            }

            if ($decision['trigger'] === 'missed') {
                $this->record($station, $job, $decision, [
                    'status' => 'error',
                    'message' => 'Horaire du ' . $decision['ref']->format('d/m/Y H:i')
                        . ' manqué (serveur indisponible ?). Utilisez « Télécharger maintenant » si nécessaire.',
                    'files' => [],
                    'hash' => null,
                ], forceFinal: true);
                continue;
            }

            $this->runJob($station, $job, $decision, $state);
        }
    }

    /**
     * Runs one job immediately (console command).
     */
    public function runNow(Station $station, string $jobId): array
    {
        $data = $this->store->read($station);
        $index = Store::findIndex($data['jobs'], $jobId);
        if ($index === null) {
            return ['status' => 'error', 'message' => 'Émission introuvable : ' . $jobId, 'files' => [], 'hash' => null];
        }
        $job = $data['jobs'][$index];
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());

        return $this->runJob(
            $station,
            $job,
            ['trigger' => 'manual', 'ref' => $now, 'slot' => null],
            $data['state'][$jobId] ?? []
        );
    }

    private function runJob(Station $station, array $job, array $decision, array $state): array
    {
        // Mark as running so that the panel can show it.
        $this->store->update($station, static function (array $data) use ($job): array {
            $data['state'][$job['id']]['running_since'] = time();

            return $data;
        });

        try {
            $result = $this->runner->execute(
                $station,
                $job,
                $decision['ref'],
                isset($state['last_hash']) ? (string)$state['last_hash'] : null,
                $decision['trigger'] !== 'manual'
            );
        } catch (Throwable $e) {
            $this->logger->error('Show downloader: ' . $e->getMessage(), ['exception' => $e]);
            $result = ['status' => 'error', 'message' => $e->getMessage(), 'files' => [], 'hash' => null];
        }

        $this->record($station, $job, $decision, $result);

        return $result;
    }

    private function record(Station $station, array $job, array $decision, array $result, bool $forceFinal = false): void
    {
        $tz = $station->getTimezoneObject();
        $this->store->update($station, static function (array $data) use ($job, $decision, $result, $forceFinal, $tz): array {
            $id = (string)$job['id'];
            // The job may have been edited or deleted during the download.
            if (Store::findIndex($data['jobs'], $id) === null) {
                unset($data['state'][$id]);

                return $data;
            }

            $state = $data['state'][$id] ?? [];
            unset($state['running_since']);
            $now = time();

            if ($forceFinal) {
                $state['handled_slot'] = $decision['slot'];
                $state['attempts'] = 0;
                unset($state['retry_at']);
                $state['last_run'] = $now;
                $state['last_status'] = $result['status'];
                $final = true;
            } else {
                $outcome = Scheduler::afterRun($job, $state, $decision, $result['status'], $now);
                $state = $outcome['state'];
                $final = $outcome['final'];
            }

            if ($result['hash'] !== null && in_array($result['status'], ['success', 'skipped'], true)) {
                $state['last_hash'] = $result['hash'];
            }
            $message = $result['message'];
            if (!$final && isset($state['retry_at'])) {
                $message .= sprintf(
                    ' Nouvel essai à %s (%d/%d).',
                    (new DateTimeImmutable('@' . $state['retry_at']))
                        ->setTimezone($tz)
                        ->format('H:i'),
                    (int)$state['attempts'],
                    (int)$job['schedule']['retry_count']
                );
            }
            $state['last_message'] = $message;
            $data['state'][$id] = $state;

            $data['history'][] = [
                'at' => $now,
                'job' => $id,
                'name' => $job['name'],
                'trigger' => $decision['trigger'],
                'status' => $result['status'],
                'final' => $final,
                'message' => $message,
                'files' => $result['files'],
            ];

            return $data;
        });
    }
}
