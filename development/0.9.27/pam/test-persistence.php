<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPam.php';
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "PDO_SQLITE_REQUIRED\n");
    exit(2);
}
$checks = 0;
function checkPersist(bool $ok, string $message): void {
    global $checks;
    ++$checks;
    if (!$ok) { throw new RuntimeException('FAIL ' . $message); }
    echo 'PASS ' . $message . PHP_EOL;
}
$path = tempnam(sys_get_temp_dir(), 'kicom-pam-wal-');
$backup = tempnam(sys_get_temp_dir(), 'kicom-pam-snap-');
$old = tempnam(sys_get_temp_dir(), 'kicom-pam-v1-');
if ($path === false || $backup === false || $old === false) {
    throw new RuntimeException('Cannot create isolated SQLite test files');
}
@unlink($backup); // VACUUM INTO must create its own fresh destination.
$db = null;
$restored = null;
$oldDb = null;
$clock = 800000;
try {
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE existing_kicom_table (id INTEGER PRIMARY KEY,value TEXT)');
    $db->exec("INSERT INTO existing_kicom_table(value) VALUES('retain-existing-data')");
    $pam = new KiComPam($db, static function () use (&$clock): int { return $clock; });
    $sha = str_repeat('e', 64);
    $lease = str_repeat('f', 64);
    $id = $pam->queue('resume-1', 'Reconcile after crash', $sha, 'internal');
    $pam->observe('KiCom', 'db', 'AVAILABLE', 'KCL:SQLITE_STATUS', $sha, 120);
    $pam->checkpoint('run-before-crash', $sha, 'Original run saved');
    checkPersist($pam->claimInternal($id, $lease, 5), 'Work was leased before simulated crash');
    unset($pam);
    $db = null;
    $clock += 6; // Missing response: previous work may have had side effects.
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pam = new KiComPam($db, static function () use (&$clock): int { return $clock; });
    checkPersist(!$pam->claimInternal($id, str_repeat('a',64), 5), 'Reopened WAL database quarantines expired work');
    checkPersist($db->query('SELECT state FROM pam_actions WHERE id=' . $id)->fetchColumn() === 'NEEDS_RECONCILIATION',
        'Uncertain action persisted across a process restart');
    checkPersist($pam->latestCheckpoint()['run_key'] === 'run-before-crash',
        'Last checkpoint retained after restart');
    checkPersist($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok',
        'SQLite WAL database integrity check passes');

    $db->exec('VACUUM INTO ' . $db->quote($backup));
    $restored = new PDO('sqlite:' . $backup);
    $restored->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    checkPersist($restored->query('PRAGMA integrity_check')->fetchColumn() === 'ok',
        'SQLite snapshot integrity check passes');
    $snapshotPam = new KiComPam($restored, static function () use (&$clock): int { return $clock; });
    checkPersist($snapshotPam->latestCheckpoint()['run_key'] === 'run-before-crash',
        'Snapshot preserves the latest checkpoint');
    checkPersist($restored->query('SELECT state FROM pam_actions WHERE id=' . $id)->fetchColumn() === 'NEEDS_RECONCILIATION',
        'Snapshot preserves uncertain work without replaying it');
    checkPersist($restored->query('SELECT value FROM existing_kicom_table')->fetchColumn() === 'retain-existing-data',
        'Snapshot preserves the pre-existing KiCom table');
    checkPersist((int) $restored->query('SELECT COUNT(*) FROM pam_action_events')->fetchColumn() === 1,
        'Snapshot preserves the immutable lease-expiry audit event');
    checkPersist(!$snapshotPam->claimInternal($id, str_repeat('b',64), 5),
        'Restored snapshot does not auto-replay uncertain work');

    $oldDb = new PDO('sqlite:' . $old);
    $oldDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $oldDb->exec('CREATE TABLE pam_schema(version INTEGER NOT NULL)');
    $oldDb->exec('INSERT INTO pam_schema(version) VALUES(1)');
    $oldDb->exec('CREATE TABLE existing_kicom_table (id INTEGER PRIMARY KEY,value TEXT)');
    $oldDb->exec("INSERT INTO existing_kicom_table(value) VALUES('v1-preserved')");
    try {
        new KiComPam($oldDb);
        checkPersist(false, 'Prior prototype v1 must not silently upgrade');
    } catch (RuntimeException $e) {
        checkPersist(str_contains($e->getMessage(), 'Unknown PAM schema version'),
            'Prior prototype schema requires an explicit reviewed migration');
    }
    checkPersist($oldDb->query('SELECT value FROM existing_kicom_table')->fetchColumn() === 'v1-preserved',
        'Unsupported schema detection leaves existing data intact');
    echo "PAM_PERSISTENCE_TESTS_PASSED=$checks\n";
} finally {
    $db = null;
    $restored = null;
    $oldDb = null;
    foreach ([$path, $backup, $old] as $p) {
        @unlink($p);
        @unlink($p . '-wal');
        @unlink($p . '-shm');
    }
}
