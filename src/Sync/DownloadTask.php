<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Sync;

use App\Entity\Station;
use App\Sync\Task\AbstractTask;
use Plugin\AzuracastShowDownloader\Service\Executor;
use Throwable;

/**
 * Runs every minute inside AzuraCast's synchronization (azuracast:sync:run).
 * Declared as a long task: a download can take several minutes; AzuraCast's lock
 * prevents two runs from overlapping while other sync tasks keep running.
 */
final class DownloadTask extends AbstractTask
{
    public function __construct(
        private readonly Executor $executor
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public static function isLongTask(): bool
    {
        return true;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            /** @var Station $station */
            if (!$station->is_enabled) {
                continue;
            }
            try {
                $this->executor->processStation($station);
            } catch (Throwable $e) {
                $this->logger->error('Show downloader: ' . $e->getMessage(), [
                    'station' => $station->id,
                    'exception' => $e,
                ]);
            }
        }
    }
}
