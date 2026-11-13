<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Service;

use DateTimeImmutable;

/**
 * Decides, once per minute, whether a job must run. Times are in the station's time zone.
 *
 * Per-job state keys:
 *   handled_slot   timestamp of the last scheduled slot that is finished (success or given up)
 *   not_before     slots before this timestamp are ignored (set when a job is created or rescheduled)
 *   attempts       failed attempts for the current slot
 *   retry_at       timestamp of the next retry
 *   run_requested  "Télécharger maintenant" was clicked
 */
final class Scheduler
{
    /** A slot whose first attempt could not start within this delay (server down…) is skipped. */
    public const int CATCH_UP_SECONDS = 3600;

    /**
     * @return array{trigger: string, ref: DateTimeImmutable, slot: ?int}|null
     *         trigger: manual | schedule | retry | missed
     */
    public static function decide(array $job, array $state, DateTimeImmutable $now): ?array
    {
        if (!empty($state['run_requested'])) {
            return ['trigger' => 'manual', 'ref' => $now, 'slot' => null];
        }
        if (empty($job['enabled'])) {
            return null;
        }

        $slot = self::latestSlot($job['schedule']['slots'] ?? [], $now);
        if ($slot === null) {
            return null;
        }
        $slotTs = $slot->getTimestamp();
        if ($slotTs <= (int)($state['handled_slot'] ?? 0) || $slotTs < (int)($state['not_before'] ?? 0)) {
            return null;
        }

        $schedule = $job['schedule'];
        $retryWindow = (int)$schedule['retry_interval'] * 60 * (int)$schedule['retry_count'];
        if ($now->getTimestamp() > $slotTs + self::CATCH_UP_SECONDS + $retryWindow) {
            return ['trigger' => 'missed', 'ref' => $slot, 'slot' => $slotTs];
        }
        if ((int)($state['retry_at'] ?? 0) > $now->getTimestamp()) {
            return null;
        }

        return [
            'trigger' => ((int)($state['attempts'] ?? 0)) > 0 ? 'retry' : 'schedule',
            'ref' => $slot,
            'slot' => $slotTs,
        ];
    }

    /**
     * Most recent slot at or before $now, looking back one week.
     */
    public static function latestSlot(array $slots, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $latest = null;
        $today = $now->setTime(0, 0);
        for ($back = 0; $back <= 7; $back++) {
            $day = $today->modify('-' . $back . ' days');
            $weekday = (int)$day->format('N');
            foreach ($slots as $slot) {
                if (!in_array($weekday, $slot['days'] ?? [], true)) {
                    continue;
                }
                [$h, $m] = array_map('intval', explode(':', (string)$slot['time']));
                $candidate = $day->setTime($h, $m);
                if ($candidate <= $now && ($latest === null || $candidate > $latest)) {
                    $latest = $candidate;
                }
            }
            if ($latest !== null) {
                return $latest;
            }
        }

        return $latest;
    }

    /**
     * Next slot after $now (for display), looking ahead one week.
     */
    public static function nextSlot(array $slots, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $next = null;
        $today = $now->setTime(0, 0);
        for ($ahead = 0; $ahead <= 7; $ahead++) {
            $day = $today->modify('+' . $ahead . ' days');
            $weekday = (int)$day->format('N');
            foreach ($slots as $slot) {
                if (!in_array($weekday, $slot['days'] ?? [], true)) {
                    continue;
                }
                [$h, $m] = array_map('intval', explode(':', (string)$slot['time']));
                $candidate = $day->setTime($h, $m);
                if ($candidate > $now && ($next === null || $candidate < $next)) {
                    $next = $candidate;
                }
            }
            if ($next !== null) {
                return $next;
            }
        }

        return null;
    }

    /**
     * Applies the outcome of a run to the job state.
     *
     * @return array{state: array, final: bool}
     */
    public static function afterRun(array $job, array $state, array $decision, string $status, int $now): array
    {
        $state['last_run'] = $now;
        $state['last_status'] = $status;

        if ($decision['trigger'] === 'manual') {
            unset($state['run_requested']);

            return ['state' => $state, 'final' => true];
        }

        if ($status === 'success' || $status === 'skipped') {
            $state['handled_slot'] = $decision['slot'];
            $state['attempts'] = 0;
            unset($state['retry_at']);

            return ['state' => $state, 'final' => true];
        }

        $attempts = (int)($state['attempts'] ?? 0) + 1;
        if ($attempts <= (int)$job['schedule']['retry_count']) {
            $state['attempts'] = $attempts;
            $state['retry_at'] = $now + (int)$job['schedule']['retry_interval'] * 60;

            return ['state' => $state, 'final' => false];
        }

        $state['handled_slot'] = $decision['slot'];
        $state['attempts'] = 0;
        unset($state['retry_at']);

        return ['state' => $state, 'final' => true];
    }
}
