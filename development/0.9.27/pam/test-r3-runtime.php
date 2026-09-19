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
require_once __DIR__ . '/KiComPamReleaseProof.php';
require_once __DIR__ . '/KiComPamReadOnlyCycle.php';
require_once __DIR__ . '/KiComPamSnapshotOrder.php';
require_once __DIR__ . '/KiComPamRecoveryGate.php';
require_once __DIR__ . '/KiComPamSnapshotSequencer.php';
require_once __DIR__ . '/KiComPamRecoveryPreflight.php';
require_once __DIR__ . '/KiComPamHighWaterVerifier.php';
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

// READ-ONLY identity diagnostics using KiCom's existing bounded pending helpers.
// All pending metadata and package files below exist ONLY in the disposable CI clone.
$beforeProof = KiComPamReleaseProof::inspectR3();
runtimeOk(($beforeProof['code'] ?? '') === 'NO_PENDING_PACKAGE',
    'No pending ZIP is not falsely interpreted as a verified release');
$r3Zip = realpath($argv[2] ?? '');
$oldZip = realpath($argv[3] ?? '');
$oldSha = '6eedd8ae7c6141fe2f8e10d323d06e7cd13cfca1a9198d55b0c4f4aed2a6f04b';
$r3Sha = '6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f';
runtimeOk(is_string($r3Zip) && hash_file('sha256', $r3Zip) === $r3Sha
    && is_string($oldZip) && hash_file('sha256', $oldZip) === $oldSha,
    'Both reference ZIPs are bound to exact bytes');
$store = kicomSelfUpdatePackagesDir();
if (!is_dir($store) && !mkdir($store, 0700, true) && !is_dir($store)) {
    throw new RuntimeException('Cannot prepare isolated package store');
}
$r3Stored = $store . '/' . $r3Sha . '.zip';
$oldStored = $store . '/' . $oldSha . '.zip';
if (!copy($r3Zip, $r3Stored) || !copy($oldZip, $oldStored)) {
    throw new RuntimeException('Cannot copy verified test ZIP into isolated package store');
}
$writePending = static function (string $sha, string $name): void {
    $pending = [
        'package_file' => $name, 'from_version' => '0.9.25',
        'to_version' => '0.9.26', 'zip_sha256' => $sha,
        'risk_class' => 'red', 'source' => 'pull:mirror',
        'created_at' => '2026-09-19T00:00:00+00:00'
    ];
    if (file_put_contents(kicomSelfUpdatePendingFile(),
        json_encode($pending, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        throw new RuntimeException('Cannot set disposable pending fixture');
    }
};
$writePending($r3Sha, basename($r3Stored));
$proof = KiComPamReleaseProof::inspectR3();
runtimeOk(($proof['code'] ?? '') === 'EXACT_R3_PENDING_BYTES_VERIFIED'
    && !empty($proof['matched'])
    && ($proof['pending_sha256'] ?? '') === $r3Sha,
    'Trusted pending metadata and on-disk ZIP jointly prove exact R3 identity');
runtimeOk(($proof['installation_permitted'] ?? null) === false
    && ($proof['human_approval_granted'] ?? null) === false,
    'Read-only identity proof never becomes production authorization');
$writePending($oldSha, basename($oldStored));
$older = KiComPamReleaseProof::inspectR3();
runtimeOk(($older['code'] ?? '') === 'DIFFERENT_PENDING_RELEASE'
    && ($older['pending_sha256'] ?? '') === $oldSha && empty($older['matched']),
    'Different ZIP with the same version is detected rather than mistaken for R3');
$writePending($r3Sha, basename($oldStored));
runtimeOk((KiComPamReleaseProof::inspectR3()['code'] ?? '') === 'PENDING_METADATA_INVALID',
    'Cross-wired package filename and declared checksum fail closed');
$writePending($oldSha, basename($oldStored));
file_put_contents($oldStored, 'tampered', LOCK_EX);
runtimeOk((KiComPamReleaseProof::inspectR3()['code'] ?? '') === 'STORED_PACKAGE_HASH_MISMATCH',
    'Stored package content tampering is independently detected');
unlink($oldStored);
symlink($oldZip, $oldStored);
runtimeOk((KiComPamReleaseProof::inspectR3()['code'] ?? '') === 'STORED_PACKAGE_UNAVAILABLE',
    'Symlink leaving the managed package directory is rejected');
unlink($oldStored);

// Simulated read-only 0.9.25 KCL observations; the underlying file/SQLite
// operations still run entirely in the previously verified isolated R3 clone.
$cycleResponses = [
    'HELLO' => "KCL/1\nOK hello\nFACT version=\"0.9.25\"\nEND\n",
    'GENOME_STATUS' => "KCL/1\nOK living_status\nFACT version=\"0.9.25\"\nFACT healthy=true\nFACT trusted=true\nFACT lkg_ok=true\nFACT drift_count=0\nFACT unknown_count=0\nEND\n",
    'SQLITE_STATUS' => "KCL/1\nOK sqlite_status\nFACT primary=true\nFACT quick_check=\"ok\"\nFACT journal_mode=\"wal\"\nEND\n",
    'UPDATE_STATUS' => "KCL/1\nOK update_status\nFACT pending_version=\"0.9.26\"\nFACT pending_risk=\"red\"\nFACT pending_source=\"pull:mirror\"\nEND\n",
];
$cycle = new KiComPamReadOnlyCycle($pam, new KiComPamKclAdapter($pam));
$writePending($r3Sha, basename($r3Stored));
$firstCycle = $cycle->run('cycle-r3-1', $cycleResponses);
runtimeOk(($firstCycle['code'] ?? '') === 'READ_ONLY_R3_IDENTITY_CONFIRMED'
    && ($firstCycle['pending_sha256'] ?? '') === $r3Sha,
    'Fixed KCL observation and exact pending bytes complete an internal read-only cycle');
runtimeOk(($firstCycle['human_approval_granted'] ?? null) === false
    && ($firstCycle['installation_permitted'] ?? null) === false,
    'PAM cycle cannot grant production installation authority');
runtimeOk($pam->perceive('KiCom:0.9.25', 'pending-r3-identity')['state'] === 'AVAILABLE',
    'Confirmed release identity is stored as expiring evidence, not as authority');
$againCycle = $cycle->run('cycle-r3-2', $cycleResponses);
runtimeOk(($againCycle['code'] ?? '') === 'PROOF_ALREADY_CHECKED_OR_UNCERTAIN',
    'Same-version and same-hash review is idempotent across separate runs');
$degraded = $cycleResponses;
$degraded['GENOME_STATUS'] = str_replace('FACT healthy=true', 'FACT healthy=false', $degraded['GENOME_STATUS']);
runtimeOk(($cycle->run('cycle-r3-degraded', $degraded)['code'] ?? '') === 'HEALTH_UNVERIFIED',
    'Unhealthy genome prevents any further internal review action');
$wrongVersion = $cycleResponses;
$wrongVersion['HELLO'] = str_replace('0.9.25', '0.9.26', $wrongVersion['HELLO']);
runtimeOk(($cycle->run('cycle-r3-version', $wrongVersion)['code'] ?? '') === 'HEALTH_UNVERIFIED',
    'Runtime/genome version disagreement fails health preflight before reuse');
$writePending($oldSha, basename($oldStored));
$wrong = $cycle->run('cycle-r3-different', $cycleResponses);
runtimeOk(($wrong['code'] ?? '') === 'PENDING_IDENTITY_UNVERIFIED'
    && ($wrong['detail'] ?? '') === 'STORED_PACKAGE_UNAVAILABLE',
    'Changed or missing pending ZIP is not accepted through the previous successful review');
runtimeOk($pam->perceive('KiCom:0.9.25', 'pending-r3-identity')['state'] === 'DEGRADED',
    'Contradictory current evidence supersedes earlier positive perception');
unlink(kicomSelfUpdatePendingFile());
runtimeOk((KiComPamReleaseProof::inspectR3()['code'] ?? '') === 'NO_PENDING_PACKAGE',
    'Release diagnostics do not leave a staged package behind');
runtimeOk(!empty(kicomSqliteHealth(true)['ok']),
    'Pending identity inspection does not modify KiCom SQLite health');
$cycleSnapshot = kicomSqliteSnapshot('pam-readonly-cycle-completed');
runtimeOk(!empty($cycleSnapshot['ok']), 'Native KiCom snapshot remains available after completed PAM cycle');
$verifiedCycleSnapshot = kicomSqliteLatestSnapshot();
runtimeOk(is_array($verifiedCycleSnapshot),
    'Native snapshot selection still returns a verified snapshot');
$cycleMetaFile = kicomSqliteSnapshotDir() . '/snapshot-' . $cycleSnapshot['id'] . '.json';
$cycleMeta = json_decode((string) file_get_contents($cycleMetaFile), true, 512, JSON_THROW_ON_ERROR);
$cycleFile = kicomSqliteSnapshotDir() . '/' . basename((string) ($cycleMeta['file_name'] ?? ''));
runtimeOk(($cycleMeta['id'] ?? '') === $cycleSnapshot['id']
    && ($cycleMeta['sha256'] ?? '') === $cycleSnapshot['sha256']
    && hash_file('sha256', $cycleFile) === $cycleSnapshot['sha256']
    && !empty(kicomSqliteVerifyFile($cycleFile, true)['ok']),
    'Exact PAM-cycle snapshot is independently verified by immutable ID and SHA');
$cycleCopy = new PDO('sqlite:' . $cycleFile, null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
runtimeOk($cycleCopy->query('PRAGMA integrity_check')->fetchColumn() === 'ok'
    && (int)$cycleCopy->query("SELECT COUNT(*) FROM pam_actions WHERE idempotency_key LIKE 'readonly-r3-identity:%' AND state='SUCCEEDED'")->fetchColumn() === 1,
    'Independent snapshot retains the single completed internal release-identity review');
runtimeOk((int)$cycleCopy->query("SELECT COUNT(*) FROM pam_action_events WHERE state='SUCCEEDED'")->fetchColumn() === 1
    && (int)$cycleCopy->query("SELECT COUNT(*) FROM pam_actions WHERE boundary='protected-external'")->fetchColumn() === 0,
    'Snapshot preserves one audited result and no protected external installation task');



// Snapshot order is a separate safety property from integrity. Verify that
// the existing native R3 manifests and files can be checked without trusting
// the random suffix of a same-second snapshot ID.
$firstMeta = json_decode((string)file_get_contents(
    kicomSqliteSnapshotDir() . '/snapshot-' . $snapshot['id'] . '.json'),
    true, 512, JSON_THROW_ON_ERROR);
$nativeVerify = static function (array $metadata): bool {
    $name = $metadata['file_name'] ?? '';
    if (!is_string($name)
        || $name !== 'snapshot-' . ($metadata['id'] ?? '') . '.sqlite') {
        return false;
    }
    $file = kicomSqliteSnapshotDir() . '/' . $name;
    return is_file($file) && !is_link($file)
        && hash_file('sha256', $file) === ($metadata['sha256'] ?? '')
        && !empty(kicomSqliteVerifyFile($file, true)['ok']);
};
$orderResult = KiComPamSnapshotOrder::select([$firstMeta, $cycleMeta], $nativeVerify);
$sameSecond = $firstMeta['created_at'] === $cycleMeta['created_at'];
runtimeOk($sameSecond
    ? (!$orderResult['ok'] && $orderResult['code'] === 'SNAPSHOT_ORDER_AMBIGUOUS')
    : ($orderResult['ok'] && $orderResult['snapshot']['id'] === $cycleMeta['id']),
    'Native snapshot selection respects timestamp order or rejects same-second ambiguity');
$badManifest = $cycleMeta;
$badManifest['sha256'] = str_repeat('0', 64);
runtimeOk((KiComPamSnapshotOrder::select([$firstMeta, $badManifest], $nativeVerify)['code'] ?? '')
    === 'SNAPSHOT_VERIFICATION_FAILED',
    'A valid older native backup never conceals failed integrity of newer metadata');
runtimeOk((KiComPamSnapshotOrder::select([$cycleMeta], $nativeVerify)['snapshot']['id'] ?? '')
    === $cycleSnapshot['id'],
    'An explicitly identified and verified native snapshot remains selectable');


$recovery = KiComPamRecoveryGate::inspect();
runtimeOk($sameSecond
    ? (!$recovery['ok'] && $recovery['code'] === 'SNAPSHOT_ORDER_AMBIGUOUS')
    : ($recovery['ok'] && $recovery['snapshot_id'] === $cycleSnapshot['id']),
    'Read-only native recovery preflight detects ambiguous same-second snapshots');
if (!empty($recovery['ok'])) {
    runtimeOk($recovery['inspection_only'] === true
        && $recovery['restore_permitted'] === false,
        'Recovery preflight returns identity but cannot authorize restoration');
}
// An identical-timestamp fixture tests ambiguity independent of runner speed.
$collisionId = substr($cycleSnapshot['id'], 0, 15) . 'eeeeeeeeee';
if ($collisionId === $cycleSnapshot['id']) {
    $collisionId = substr($cycleSnapshot['id'], 0, 15) . 'dddddddddd';
}
$collision = $cycleMeta;
$collision['id'] = $collisionId;
$collision['file_name'] = 'snapshot-' . $collisionId . '.sqlite';
$collisionJson = kicomSqliteSnapshotDir() . '/snapshot-' . $collisionId . '.json';
$collisionFile = kicomSqliteSnapshotDir() . '/' . $collision['file_name'];
if (!copy($cycleFile, $collisionFile) ||
    file_put_contents($collisionJson, json_encode($collision, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
    throw new RuntimeException('Could not create disposable collision fixture');
}
runtimeOk((KiComPamRecoveryGate::inspect()['code'] ?? '') === 'SNAPSHOT_ORDER_AMBIGUOUS',
    'Two individually valid identical-second native backups cannot be arbitrarily ordered');
unlink($collisionJson);
unlink($collisionFile);
$originalJson = (string)file_get_contents($cycleMetaFile);
$forged = $cycleMeta;
$forged['sha256'] = str_repeat('0', 64);
file_put_contents($cycleMetaFile, json_encode($forged, JSON_THROW_ON_ERROR), LOCK_EX);
runtimeOk((KiComPamRecoveryGate::inspect()['code'] ?? '') === 'SNAPSHOT_VERIFICATION_FAILED',
    'Recovery preflight rejects a corrupt newest candidate rather than silently picking an older backup');
file_put_contents($cycleMetaFile, $originalJson, LOCK_EX);


// Live post-install transition is modeled only in the disposable clone.
// No pending update means no release review or protected action is enqueued.
$postInstall = [
    'HELLO' => "KCL/1\nOK hello\nFACT version=\"0.9.26\"\nEND\n",
    'GENOME_STATUS' => "KCL/1\nOK living_status\nFACT version=\"0.9.26\"\nFACT genome_id=\"kicom-0.9.26-g25r3\"\nFACT healthy=true\nFACT trusted=true\nFACT lkg_ok=true\nFACT drift_count=0\nFACT unknown_count=0\nEND\n",
    'SQLITE_STATUS' => "KCL/1\nOK sqlite_status\nFACT primary=true\nFACT quick_check=\"ok\"\nFACT journal_mode=\"wal\"\nEND\n",
    'UPDATE_STATUS' => "KCL/1\nOK update_status\nFACT pending_version=\"\"\nFACT pending_risk=\"\"\nFACT pending_source=\"\"\nEND\n",
];
$preActionCount = (int)$db->query('SELECT COUNT(*) FROM pam_actions')->fetchColumn();
$postCycle = $cycle->run('r3-installed-no-pending', $postInstall);
runtimeOk($postCycle['code'] === 'NO_SUPPORTED_RED_RELEASE_REVIEW'
    && !empty($pam->perceive('KiCom:0.9.26','genome-integrity')['state'])
    && $pam->perceive('KiCom:0.9.26','genome-integrity')['state'] === 'AVAILABLE',
    'First post-install observation accepts exact healthy g25r3 without invoking old release proof');
runtimeOk((int)$db->query('SELECT COUNT(*) FROM pam_actions')->fetchColumn() === $preActionCount
    && $pam->perceive('KiCom:0.9.26','production-install')['state'] === 'UNKNOWN',
    'No pending release creates no action and no new installation authority');
runtimeOk($pam->latestCheckpoint()['run_key'] === 'r3-installed-no-pending',
    'Installed R3 state is persisted as next-run checkpoint');


// In a disposable, initially empty journal directory, test ordering of two
// real R3-produced SQLite snapshots. Publication copy is intentionally kept
// separate from the native production-style snapshot folder; this does not
// activate the sequencer in the native recovery kernel.
$seqDir = $root . '/var/pam-sequence-compatibility';
if (!mkdir($seqDir, 0700, true)) {
    throw new RuntimeException('Failed to prepare isolated sequencer fixture');
}
$seqRuntime = new KiComPamSnapshotSequencer($seqDir);
$nativeThroughFixture = static function () use ($seqDir): array {
    $native = kicomSqliteSnapshot('isolated-sequence-compatibility');
    if (empty($native['ok'])) return $native;
    $id = (string)$native['id'];
    foreach (['.sqlite', '.json'] as $ext) {
        $sourceFile = kicomSqliteSnapshotDir() . '/snapshot-' . $id . $ext;
        $target = $seqDir . '/snapshot-' . $id . $ext;
        if (!copy($sourceFile, $target)) {
            throw new RuntimeException('Failed to copy exact native snapshot to test journal');
        }
    }
    return $native;
};
$seqOne = $seqRuntime->create($nativeThroughFixture);
$seqTwo = $seqRuntime->create($nativeThroughFixture);
runtimeOk(!empty($seqOne['ok']) && !empty($seqTwo['ok'])
    && $seqOne['sequence'] === 1 && $seqTwo['sequence'] === 2,
    'Two real R3 SQLite snapshots receive lock-serialized independent sequence numbers');
$seqVerified = $seqRuntime->inspect();
runtimeOk(!empty($seqVerified['ok']) && $seqVerified['snapshot_id'] === $seqTwo['snapshot_id']
    && $seqVerified['sequence'] === 2 && !$seqVerified['restore_permitted'],
    'R3-native snapshot bytes and metadata validate ordered read-only candidate without restore authority');
$legacyOrphan = $seqDir . '/snapshot-' . $snapshot['id'] . '.json';
copy(kicomSqliteSnapshotDir() . '/snapshot-' . $snapshot['id'] . '.json', $legacyOrphan);
runtimeOk($seqRuntime->inspect()['code'] === 'UNSEQUENCED_NATIVE_SNAPSHOT',
    'Legacy unsequenced R3 metadata cannot be silently adopted into the monotonic journal');
unlink($legacyOrphan);
runtimeOk($seqRuntime->inspect()['snapshot_id'] === $seqTwo['snapshot_id'],
    'Removing only isolated orphan fixture restores verified sequence selection');

// Compare an externally retained high-water claim with an actual R3-generated
// SQLite snapshot sequence. Test claim lives outside the sequence directory;
// it does not pretend that the production independent signer exists.
$testAnchor = [
    'sequence' => $seqTwo['sequence'],
    'snapshot_id' => $seqTwo['snapshot_id'],
    'snapshot_sha256' => $seqTwo['snapshot_sha256'],
    'entry_sha256' => hash_file('sha256', $seqDir . '/pam-sequence/entry-0000000002.json')
];
$highWater = static fn(?array $a): array =>
    KiComPamHighWaterVerifier::inspect($seqRuntime, $seqDir, $a);
runtimeOk($highWater(null)['code'] === 'INDEPENDENT_HIGH_WATER_ANCHOR_MISSING',
    'Real R3 snapshot sequence without independent high-water claim is not recovery eligible');
$highMatch = $highWater($testAnchor);
runtimeOk(!empty($highMatch['ok']) && $highMatch['snapshot_id'] === $seqTwo['snapshot_id']
    && !$highMatch['restore_permitted'] && !$highMatch['automatic_recovery_permitted']
    && !$highMatch['independent_anchor_authenticated_here'],
    'Real R3 backup matches external test claim but never self-authenticates or authorizes restoration');
$tailPaths = [
    $seqDir . '/pam-sequence/entry-0000000002.json',
    $seqDir . '/snapshot-' . $seqTwo['snapshot_id'] . '.json',
    $seqDir . '/snapshot-' . $seqTwo['snapshot_id'] . '.sqlite'
];
$originalTail = array_map(static fn(string $p): string => (string)file_get_contents($p), $tailPaths);
foreach ($tailPaths as $p) {
    unlink($p);
}
runtimeOk(!empty($seqRuntime->inspect()['ok'])
    && $seqRuntime->inspect()['sequence'] === 1
    && $highWater($testAnchor)['code'] === 'HIGH_WATER_ROLLBACK_OR_DIVERGENCE',
    'High-water claim detects truncation of a locally self-consistent native R3 journal and backup');
foreach ($tailPaths as $i => $p) {
    file_put_contents($p, $originalTail[$i], LOCK_EX);
}
runtimeOk(!empty($highWater($testAnchor)['ok']),
    'Exact native R3 snapshot and ledger bytes recover prior identity without rewriting the anchor');



// Pre-quarantine recovery preflight must never convert an unanchored local
// journal or same-second legacy snapshot into automatic restore authority.
$originalDbPath = kicomSqliteFile();
$nativeStateBefore = [];
foreach (['', '-wal'] as $suffix) {
    $candidate = $originalDbPath . $suffix;
    $nativeStateBefore[$suffix] = is_file($candidate) ? hash_file('sha256', $candidate) : null;
}
$quarantineBefore = glob(kicomSqliteQuarantineDir() . '/*') ?: [];
$eventsBefore = (int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn();
$legacyPreflight = KiComPamRecoveryPreflight::inspect();
runtimeOk(($legacyPreflight['inspection_only'] ?? false) === true
    && ($legacyPreflight['restore_permitted'] ?? true) === false
    && ($legacyPreflight['automatic_recovery_permitted'] ?? true) === false,
    'Legacy R3 recovery preflight never grants automatic DB replacement');
runtimeOk($legacyPreflight['code'] === 'LEGACY_SNAPSHOT_ORDER_AMBIGUOUS'
    || $legacyPreflight['code'] === 'LEGACY_CANDIDATE_REQUIRES_REVIEW'
    || $legacyPreflight['code'] === 'LEGACY_SNAPSHOT_VERIFICATION_FAILED',
    'Legacy backup selection surfaces unresolved ordering or review rather than assuming safety');
// The temporary sequencer wraps a native writer but the previously produced
// R3 files are NOT journaled: mixing them must stop before any quarantine.
$nativeSequencer = new KiComPamSnapshotSequencer(kicomSqliteSnapshotDir());
$nativeSequence = $nativeSequencer->create(
    static fn(): array => kicomSqliteSnapshot('pam-sequence-preflight-only'));
runtimeOk(!empty($nativeSequence['ok']) && $nativeSequence['sequence'] === 1,
    'Fresh native writer can publish first isolated sequence ledger entry');
$unanchored = KiComPamRecoveryPreflight::inspect();
runtimeOk(!$unanchored['ok']
    && $unanchored['code'] === 'SEQUENCE_UNSEQUENCED_NATIVE_SNAPSHOT'
    && !$unanchored['automatic_recovery_permitted'],
    'Mixed legacy and sequenced native snapshots fail closed prior to recovery');
runtimeOk((int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn() === $eventsBefore
    && (glob(kicomSqliteQuarantineDir() . '/*') ?: []) === $quarantineBefore,
    'Read-only preflight leaves R3 DB events and quarantine untouched');
$nativeStateAfter = [];
foreach (['', '-wal'] as $suffix) {
    $candidate = $originalDbPath . $suffix;
    $nativeStateAfter[$suffix] = is_file($candidate) ? hash_file('sha256', $candidate) : null;
}
runtimeOk($nativeStateBefore === $nativeStateAfter,
    'Blocked recovery preflight preserves exact original SQLite database and WAL bytes');


echo "PAM_R3_RUNTIME_TESTS_PASSED=$checks\n";
