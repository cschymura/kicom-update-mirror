<?php
declare(strict_types=1);

/**
 * Standalone integration smoke against an isolated copy of the EXACT R3 source.
 * Never run against production paths. No activation or KiCom release mutation.
 */
if (!extension_loaded('pdo_sqlite') || !class_exists('SQLite3')) {
    fwrite(STDERR, "PDO_SQLITE_AND_SQLITE3_REQUIRED\n");
    exit(2);
}
$root = realpath($argv[1] ?? '');
$expected = realpath(sys_get_temp_dir() . '/kicom-pam-r3-runtime');
if ($root === false || $expected === false || $root !== $expected) {
    throw new RuntimeException('Only the CI-isolated runtime directory may be used');
}
if (is_file($root . '/var/sqlite/kicom.sqlite')) {
    throw new RuntimeException('Isolated runtime unexpectedly contains a pre-existing SQLite database');
}
$source = json_decode((string) file_get_contents($root . '/SOURCE-SNAPSHOT.json'), true, 512, JSON_THROW_ON_ERROR);
if (($source['release_revision'] ?? '') !== 'R3'
    || ($source['genome'] ?? '') !== 'kicom-0.9.26-g25r3'
    || ($source['release_sha256'] ?? '') !== '6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f') {
    throw new RuntimeException('Unexpected KiCom R3 runtime snapshot');
}
chdir($root);
require_once $root . '/lib.php';
require_once __DIR__ . '/KiComPam.php';
require_once __DIR__ . '/KiComPamKclAdapter.php';
$checks = 0;
function runtimeOk(bool $value, string $message): void {
    global $checks;
    ++$checks;
    if (!$value) {
        throw new RuntimeException('FAIL ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}
runtimeOk(kicomEnsureStorage(), 'Actual R3 storage and canonical-memory initialization succeeds');
$db = kicomSqliteDb();
runtimeOk($db instanceof PDO, 'Actual R3 SQLite PDO is reachable');
$initial = kicomSqliteHealth(true);
runtimeOk(!empty($initial['ok']) && ($initial['integrity_check'] ?? '') === 'ok'
    && ($initial['schema_version'] ?? 0) === 1, 'Actual R3 schema and full SQLite integrity healthy');
$originalTables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
runtimeOk(!in_array('pam_actions', $originalTables, true), 'PAM tables absent before isolated integration');
$pam = new KiComPam($db, static fn(): int => 900000);
$existingSchema = (int) $db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
runtimeOk($existingSchema === 1, 'PAM schema does not change KiCom native schema_migrations');
runtimeOk((int) $db->query('SELECT version FROM pam_schema')->fetchColumn() === 2,
    'PAM v2 schema coexists on the actual R3 PDO connection');
$added = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'pam_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
runtimeOk(count($added) === 5, 'Only the five expected PAM tables are created');
runtimeOk($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Native SQLite integrity preserved after PAM migration');
$sha = str_repeat('7', 64);
$pam->observe('KiCom:0.9.26', 'sqlite-quick-check', 'AVAILABLE', 'KCL:SQLITE_STATUS', $sha, 300);
$task = $pam->queue('r3-pam-isolation', 'Run PAM R3 compatibility smoke', $sha, 'internal');
$pam->checkpoint('r3-compatibility', $sha, 'PAM extension tested on isolated runtime');
runtimeOk($task > 0 && $pam->latestCheckpoint()['run_key'] === 'r3-compatibility',
    'Isolated R3 integration persists PAM task and checkpoint');
$after = kicomSqliteHealth(true);
runtimeOk(!empty($after['ok']) && ($after['schema_version'] ?? 0) === 1,
    'Native R3 full health remains OK after PAM writes');
$snapshot = kicomSqliteSnapshot('pam-r3-compatibility-isolated');
runtimeOk(!empty($snapshot['ok']) && ($snapshot['code'] ?? '') === 'SQLITE_SNAPSHOT_CREATED',
    'Unmodified R3 native SQLite snapshot succeeds with PAM tables');
$latest = kicomSqliteLatestSnapshot();
runtimeOk(is_array($latest) && ($latest['sha256'] ?? '') === ($snapshot['sha256'] ?? null),
    'Native R3 snapshot manifest/hash verification succeeds');
$copy = new PDO('sqlite:' . $latest['full'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
runtimeOk($copy->query('PRAGMA integrity_check')->fetchColumn() === 'ok',
    'Native R3 snapshot passes independent full SQLite integrity check');
runtimeOk((int) $copy->query('SELECT version FROM pam_schema')->fetchColumn() === 2
    && (int) $copy->query('SELECT COUNT(*) FROM pam_observations')->fetchColumn() === 1
    && (int) $copy->query('SELECT COUNT(*) FROM pam_checkpoints')->fetchColumn() === 1,
    'Native R3 snapshot preserves PAM v2 observation and checkpoint');
runtimeOk((int) $copy->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === 1,
    'Native R3 schema remains unchanged in snapshot');
echo "PAM_R3_RUNTIME_TESTS_PASSED=$checks\n";
