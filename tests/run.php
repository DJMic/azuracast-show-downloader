<?php

declare(strict_types=1);

// Standalone tests for the pure logic (no AzuraCast needed): php tests/run.php

require __DIR__ . '/../src/Service/Naming.php';
require __DIR__ . '/../src/Service/Job.php';
require __DIR__ . '/../src/Service/Scheduler.php';

use Plugin\AzuracastShowDownloader\Service\Job;
use Plugin\AzuracastShowDownloader\Service\Naming;
use Plugin\AzuracastShowDownloader\Service\Scheduler;

$failures = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        echo "ÉCHEC  $label\n  attendu : " . var_export($expected, true) . "\n  obtenu  : " . var_export($actual, true) . "\n";
    } else {
        echo "ok     $label\n";
    }
}

$tz = new DateTimeZone('Europe/Brussels');
$d = new DateTimeImmutable('2026-03-05 10:00', $tz);

check('format YYYYMMDD', Naming::formatDate('YYYYMMDD', $d), '20260305');
check('format DD-MM-YY', Naming::formatDate('DD-MM-YY', $d), '05-03-26');
check('format D.M.YYYY', Naming::formatDate('D.M.YYYY', $d), '5.3.2026');
check('render {date}', Naming::render('Show_{date}.mp3', [], $d, 'YYYYMMDD'), 'Show_20260305.mp3');
check('render {date:FMT}', Naming::render('Show_{date:DD.MM.YYYY}', [], $d, 'YYYYMMDD'), 'Show_05.03.2026');
check('target keep name', Naming::targetName('Abc 1.MP3', false, '', 'X', 1, $d, 'YYYYMMDD'), 'Abc 1.MP3');
check('target rename adds ext', Naming::targetName('emission_0305.mp3', true, 'Matinale', 'X', 1, $d, 'YYYYMMDD'), 'Matinale.mp3');
check('target rename date', Naming::targetName('a.mp3', true, '{show} {date:YYYY-MM-DD}', 'Ma Matinale', 1, $d, 'YYYYMMDD'), 'Ma Matinale 2026-03-05.mp3');
check('target rename numeric tail', Naming::targetName('a.mp3', true, 'Show_{date:DD.MM.YYYY}', 'S', 1, $d, 'Y'), 'Show_05.03.2026.mp3');
check('target rename with ext', Naming::targetName('a.wav', true, '{original}_{n}.{ext}', 'S', 2, $d, 'Y'), 'a_2.wav');
check('sanitize slash', Naming::sanitizeFilename('a/b\\c'), 'a-b-c');
check('dir normalize', Naming::normalizeDirectory('/Emissions//Matin/ '), 'Emissions/Matin');
check('match wildcard', Naming::matches('show_*.MP3', 'Show_12.mp3'), true);
check('match exact', Naming::matches('a.mp3', 'b.mp3'), false);
try {
    Naming::normalizeDirectory('../etc');
    check('dir .. refused', false, true);
} catch (InvalidArgumentException) {
    check('dir .. refused', true, true);
}

// Job normalization and secrets.
$input = [
    'name' => 'Matinale',
    'source' => ['type' => 'ftp', 'host' => 'ftp://ftp.example.org/', 'username' => 'radio', 'password' => 's3cret', 'remote_dir' => '/out/'],
    'file' => ['pattern' => 'mat_{date}.mp3', 'date_enabled' => true, 'date_format' => 'YYMMDD', 'date_offset' => -1],
    'target' => ['directory' => 'Emissions/Matinale', 'rename_enabled' => true, 'rename_pattern' => 'Matinale'],
    'schedule' => ['slots' => [['days' => [5, 1, 1], 'time' => '06:30']], 'retry_count' => 4],
];
$job = Job::normalize($input, null);
check('job host cleaned', $job['source']['host'], 'ftp.example.org');
check('job port default', $job['source']['port'], 21);
check('job remote dir', $job['source']['remote_dir'], '/out');
check('job days sorted', $job['schedule']['slots'][0]['days'], [1, 5]);
$display = Job::forDisplay($job);
check('display hides password', $display['source']['password'], null);
check('display has_password', $display['source']['has_password'], true);
$input2 = $input;
$input2['source']['password'] = null;
$job2 = Job::normalize($input2, $job);
check('password kept when null', $job2['source']['password'], 's3cret');
check('id kept', $job2['id'], $job['id']);
try {
    $bad = $input;
    $bad['file']['pattern'] = 'mat.mp3';
    Job::normalize($bad, null);
    check('date without {date} refused', false, true);
} catch (InvalidArgumentException) {
    check('date without {date} refused', true, true);
}

// Scheduler.
$job['enabled'] = true;
$job['schedule']['retry_interval'] = 15;
$now = new DateTimeImmutable('2026-03-06 06:31', $tz); // Friday
$slot = Scheduler::latestSlot($job['schedule']['slots'], $now);
check('latest slot friday', $slot?->format('Y-m-d H:i'), '2026-03-06 06:30');
check('latest slot before time', Scheduler::latestSlot($job['schedule']['slots'], new DateTimeImmutable('2026-03-06 06:00', $tz))?->format('Y-m-d H:i'), '2026-03-02 06:30');
check('next slot', Scheduler::nextSlot($job['schedule']['slots'], $now)?->format('Y-m-d H:i'), '2026-03-09 06:30');

$state = ['not_before' => $now->modify('-2 days')->getTimestamp()];
$decision = Scheduler::decide($job, $state, $now);
check('decide schedule', $decision['trigger'] ?? null, 'schedule');
$after = Scheduler::afterRun($job, $state, $decision, 'not_found', $now->getTimestamp());
check('not found -> retry', $after['final'], false);
check('retry at +15min', $after['state']['retry_at'], $now->getTimestamp() + 900);
check('waiting for retry', Scheduler::decide($job, $after['state'], $now->modify('+5 minutes')), null);
check('retry trigger', Scheduler::decide($job, $after['state'], $now->modify('+16 minutes'))['trigger'] ?? null, 'retry');
$ok = Scheduler::afterRun($job, $after['state'], $decision, 'success', $now->getTimestamp());
check('success handled', $ok['state']['handled_slot'], $decision['slot']);
check('no rerun after success', Scheduler::decide($job, $ok['state'], $now->modify('+20 minutes')), null);
check('new job ignores past slot', Scheduler::decide($job, ['not_before' => $now->getTimestamp()], $now), null);
check('missed after window', Scheduler::decide($job, $state, $now->modify('+3 hours'))['trigger'] ?? null, 'missed');
check('manual first', Scheduler::decide($job, ['run_requested' => 1], $now)['trigger'] ?? null, 'manual');
$job['enabled'] = false;
check('disabled no run', Scheduler::decide($job, $state, $now), null);

echo $failures === 0 ? "\nTous les tests réussis.\n" : "\n$failures test(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
