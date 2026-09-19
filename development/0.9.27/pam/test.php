<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPam.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "PDO_SQLITE_REQUIRED\n");
    exit(2);
}

$checks = 0;
function ok(bool $value, string $message): void {
    global $checks;
    ++$checks;
    if (!$value) {
        throw new RuntimeException('FAIL ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}
function rejects(callable $work, string $message): void {
    try {
        $work();
    } catch (InvalidArgumentException|RuntimeException $e) {
        ok(true, $message);
        return;
    }
    ok(false, $message);
}

$file = tempnam(sys_get_temp_dir(), 'kicom-pam-');
if ($file === false) {
    throw new RuntimeException('Cannot create temporary database');
}
$now = 100000;
try {
    $db = new PDO('sqlite:' . $file);
    $db->exec('CREATE TABLE existing_kicom_table (id INTEGER PRIMARY KEY, value TEXT)');
    $db->exec("INSERT INTO existing_kicom_table(value) VALUES('preserve-me')");
    $pam = new KiComPam($db, static function () use (&$now): int { return $now; });
    $other = new KiComPam(new PDO('sqlite:' . $file), static function () use (&$now): int { return $now; });
    $sha = str_repeat('a', 64);
    $otherSha = str_repeat('b', 64);
    $lease1 = str_repeat('c', 64);
    $lease2 = str_repeat('d', 64);

    ok($pam->perceive('server', 'health')['state'] === 'UNKNOWN', 'Unknown until observed');
    $pam->observe('server', 'health', 'AVAILABLE', 'KCL:GENOME_STATUS', $sha, 10);
    ok($pam->perceive('server', 'health')['state'] === 'AVAILABLE', 'Fresh sourced observation');
    $now += 10;
    $stale = $pam->perceive('server', 'health');
    ok($stale['state'] === 'STALE' && $stale['recorded_state'] === 'AVAILABLE', 'Observation TTL and original evidence preserved');
    rejects(fn() => $pam->observe('s', 'c', 'AUTHORIZED', 'source', $sha, 60), 'Observation cannot create authority state');
    rejects(fn() => $pam->observe('s', 'c', 'AVAILABLE', 'source', 'not-a-sha', 60), 'Evidence checksum required');

    $external = $pam->queue('release-red-0926', 'Protected production release', $sha, 'protected-external');
    ok(!$pam->claimInternal($external, $lease1), 'External action never claimable from internal queue');
    $internal = $pam->queue('dev-pam-1', 'Run isolated PAM tests', $sha, 'internal');
    ok($internal === $pam->queue('dev-pam-1', 'Run isolated PAM tests', $sha, 'internal'), 'Idempotent action insertion');
    rejects(fn() => $pam->queue('dev-pam-1', 'Changed meaning', $sha, 'internal'), 'Idempotency collision refuses changed action');
    ok($pam->claimInternal($internal, $lease1, 5), 'First owner claims internal work');
    ok(!$other->claimInternal($internal, $lease2, 5), 'Concurrent owner cannot duplicate claim');
    $now += 6;
    ok($other->claimInternal($internal, $lease2, 5), 'Expired lease may be recovered');
    ok(!$pam->finish($internal, $lease1, 'SUCCEEDED', $sha), 'Old lease owner cannot record result');
    ok($other->finish($internal, $lease2, 'SUCCEEDED', $otherSha), 'Current lease owner records audited result');
    ok(!$other->finish($internal, $lease2, 'SUCCEEDED', $otherSha), 'Completed action cannot be finalized twice');
    ok((int) $db->query('SELECT COUNT(*) FROM pam_action_events')->fetchColumn() === 1, 'One immutable outcome event');
    ok(!$pam->claimInternal($internal, $lease1), 'Completed action cannot be claimed again');

    $checkpoint = $pam->checkpoint('night-run-1', $otherSha, 'Verified read-only baseline');
    ok($checkpoint === $pam->checkpoint('night-run-1', $otherSha, 'Verified read-only baseline'), 'Idempotent durable checkpoint');
    rejects(fn() => $pam->checkpoint('night-run-1', $sha, 'Altered checkpoint'), 'Checkpoint collision fails closed');
    ok($pam->latestCheckpoint()['state_sha256'] === $otherSha, 'Latest checkpoint available for next run');
    ok($db->query('SELECT value FROM existing_kicom_table')->fetchColumn() === 'preserve-me', 'Original KiCom tables untouched');
    ok((int) $db->query('SELECT version FROM pam_schema')->fetchColumn() === 1, 'Schema version persisted');
    echo "PAM_TESTS_PASSED=$checks\n";
} finally {
    $db = null;
    $other = null;
    @unlink($file);
}
