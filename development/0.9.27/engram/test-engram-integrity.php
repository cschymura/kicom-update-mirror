<?php
declare(strict_types=1);
// Synthetic-only stand-alone data integrity regression. No live memory.
$integrityTests = 0;
function integrityAssert(bool $ok, string $label): void
{
    global $integrityTests;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $integrityTests++;
    echo 'PASS ' . $label . "\n";
}
$syntheticRoot = sys_get_temp_dir() . '/engram-integrity-synthetic-' . bin2hex(random_bytes(12));
mkdir($syntheticRoot, 0700);
mkdir($syntheticRoot . '/web', 0755);
mkdir($syntheticRoot . '/private', 0700);
try {
    $store = new KiComEngramStore($syntheticRoot . '/private', $syntheticRoot . '/web');
    mkdir($syntheticRoot . '/backups', 0700);
    mkdir($syntheticRoot . '/restore', 0700);
    mkdir($syntheticRoot . '/chain-private', 0700);
    $original = 'SYNTHETIC_APPROVED_FIXTURE';
    $entry = $store->create('subject-a', 'project', 'technical',
        $original, 'synthetic_test', 'fixture://integrity');
    $rows = $store->search('subject-a', 'project', 'SYNTHETIC');
    integrityAssert(count($rows) === 1 && $rows[0]['body'] === $original,
        'untampered synthetic revision can be retrieved');
    integrityAssert(!array_key_exists('subject', $rows[0]) && !array_key_exists('previous_hash', $rows[0]),
        'public search projection remains unchanged after hash verification');
    $audit = $store->auditHistory();
    integrityAssert($audit['revision_chain_valid'] && $audit['revision_count'] === 1,
        'untampered revision chain passes explicit read-only audit');
    $originalBackup = $store->backup($syntheticRoot . '/backups', $syntheticRoot . '/web');
    integrityAssert($originalBackup['revision_count'] === 1
        && is_file($syntheticRoot . '/backups/' . $originalBackup['filename']),
        'untampered content-hash-checked offline backup created');
    $rawDb = new PDO('sqlite:' . $syntheticRoot . '/private/engrams.sqlite');
    $rawDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $rawDb->prepare('UPDATE engram_revisions SET body = ? WHERE subject = ? AND namespace = ? AND id = ?');
    $stmt->execute(['SYNTHETIC_CHANGED_FIXTURE', 'subject-a', 'project', $entry['id']]);
    integrityAssert($store->health()['quick_check'] === 'ok',
        'SQLite structural quick_check alone does not detect a modified engram body');
    $denied = false;
    try {
        $store->search('subject-a', 'project', 'SYNTHETIC');
    } catch (RuntimeException $e) {
        $denied = $e->getMessage() === 'Engram revision content integrity check failed';
    }
    integrityAssert($denied, 'tampered synthetic latest revision is never returned by search');
    $auditRejected = false;
    try { $store->auditHistory(); } catch (RuntimeException $e) { $auditRejected = true; }
    integrityAssert($auditRejected, 'content tampering rejects full revision audit');
    $backupRejected = false;
    try { $store->backup($syntheticRoot . '/backups', $syntheticRoot . '/web'); }
    catch (RuntimeException $e) { $backupRejected = true; }
    integrityAssert($backupRejected, 'content tampering cannot enter a new backup');
    integrityAssert(count(glob($syntheticRoot . '/backups/*.sqlite') ?: []) === 1,
        'failed corrupted backup leaves prior verified snapshot untouched');
    $restored = KiComEngramStore::restore(
        $syntheticRoot . '/backups/' . $originalBackup['filename'],
        $syntheticRoot . '/restore', $syntheticRoot . '/web', $originalBackup['sha256']
    );
    $reopened = new KiComEngramStore($syntheticRoot . '/restore', $syntheticRoot . '/web');
    integrityAssert($restored['restored'] === true
        && count($reopened->search('subject-a','project','SYNTHETIC_APPROVED_FIXTURE')) === 1,
        'verified pre-tamper backup restores original synthetic memory');
    $deniedRevision = false;
    try {
        $store->revise('subject-a', 'project', $entry['id'], 1, $entry['revision_hash'],
            'synthetic new content', 'synthetic_test', 'fixture://integrity');
    } catch (RuntimeException $e) {
        $deniedRevision = true;
    }
    integrityAssert($deniedRevision, 'tampered latest revision cannot be revised');
    $chainStore = new KiComEngramStore($syntheticRoot . '/chain-private', $syntheticRoot . '/web');
    $chainFirst = $chainStore->create('subject-a','project','decision','SYNTHETIC_CHAIN_FIRST',
        'synthetic_test','fixture://chain');
    $chainStore->revise('subject-a','project',$chainFirst['id'],1,$chainFirst['revision_hash'],
        'SYNTHETIC_CHAIN_LATEST','synthetic_test','fixture://chain');
    integrityAssert($chainStore->auditHistory()['revision_count'] === 2,
        'two linked synthetic revisions pass chain verification');
    $rawChain = new PDO('sqlite:' . $syntheticRoot . '/chain-private/engrams.sqlite');
    $rawChain->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $removeOriginal = $rawChain->prepare('DELETE FROM engram_revisions WHERE id=? AND revision=1');
    $removeOriginal->execute([$chainFirst['id']]);
    integrityAssert($chainStore->health()['quick_check'] === 'ok'
        && count($chainStore->search('subject-a','project','SYNTHETIC_CHAIN_LATEST')) === 1,
        'structurally valid latest record alone cannot prove original revision exists');
    $brokenChainDenied = false;
    try { $chainStore->auditHistory(); } catch (RuntimeException $e) { $brokenChainDenied = true; }
    integrityAssert($brokenChainDenied, 'missing historical revision breaks append-only chain audit');
    $historyBackupDenied = false;
    try { $chainStore->backup($syntheticRoot . '/backups',$syntheticRoot . '/web'); }
    catch (RuntimeException $e) { $historyBackupDenied = true; }
    integrityAssert($historyBackupDenied, 'backup rejects missing historical revision');
    integrityAssert(count(glob($syntheticRoot . '/backups/*.sqlite') ?: []) === 1,
        'broken-history backup does not damage original verified snapshot');
    echo "KICOM_ENGRAM_INTEGRITY_TESTS_PASSED=$integrityTests\n";
} finally {
    unset($stmt, $rawDb, $rawChain, $removeOriginal, $chainStore, $reopened, $store);
    foreach (['private', 'chain-private', 'restore', 'backups'] as $name) {
        foreach (glob($syntheticRoot . '/' . $name . '/*') ?: [] as $f) {
            if (is_file($f) && !is_link($f)) { @unlink($f); }
        }
        @rmdir($syntheticRoot . '/' . $name);
    }
    @rmdir($syntheticRoot . '/web');
    @rmdir($syntheticRoot);
}
