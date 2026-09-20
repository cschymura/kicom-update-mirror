<?php
declare(strict_types=1);
require __DIR__ . '/KiComEngramStore.php';

$checks = 0;
function check(bool $value, string $message): void {
    global $checks;
    if (!$value) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
    echo "PASS " . $message . "\n";
}
function rejects(callable $action, string $message): void {
    $failedClosed = false;
    try { $action(); } catch (RuntimeException|InvalidArgumentException $e) { $failedClosed = true; }
    check($failedClosed, $message);
}
function recursiveRemove(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $item) {
        if ($item !== '.' && $item !== '..') { recursiveRemove($path . '/' . $item); }
    }
    rmdir($path);
}
$root = sys_get_temp_dir() . '/kicom-engram-ci-' . bin2hex(random_bytes(7));
mkdir($root, 0700);
$web = $root . '/web';
$private = $root . '/private';
mkdir($web, 0755);
mkdir($private, 0700);
try {
    rejects(static fn() => new KiComEngramStore($web, $web), 'web-root storage rejected');
    mkdir($web . '/insecure', 0700);
    rejects(static fn() => new KiComEngramStore($web . '/insecure', $web), 'nested web-root storage rejected');
    $link = $root . '/link';
    symlink($private, $link);
    rejects(static fn() => new KiComEngramStore($link, $web), 'symlink private directory rejected');
    chmod($private, 0755);
    rejects(static fn() => new KiComEngramStore($private, $web), 'world-accessible private directory rejected');
    chmod($private, 0700);
    $store = new KiComEngramStore($private, $web);
    check(($store->health()['quick_check'] ?? null) === 'ok', 'SQLite quick_check ok');
    check(($store->health()['journal_mode'] ?? null) === 'wal', 'SQLite WAL enabled');
    check((fileperms($private . '/engrams.sqlite') & 0077) === 0, 'database file private permissions');
    $one = $store->create('subject-a', 'project', 'collaboration',
        'Synthetic: summarize decisions before implementation.', 'synthetic_test', 'fixture://one');
    check(strlen($one['id']) === 32 && $one['revision'] === 1, 'new independent engram id and revision');
    $same = $store->search('subject-a', 'project', 'decisions');
    check(count($same) === 1 && $same[0]['body'] === 'Synthetic: summarize decisions before implementation.', 'literal scoped search retrieves synthetic note');
    check(count($store->search('subject-b', 'project', 'decisions')) === 0, 'cross-subject read fails');
    check(count($store->search('subject-a', 'other', 'decisions')) === 0, 'cross-namespace read fails');
    check(count($store->search('subject-a', 'project', '%')) === 0, 'percent character cannot become LIKE wildcard');
    check(count($store->search('subject-a', 'project', "' OR 1=1 --")) === 0, 'SQL-like text cannot expand search');
    rejects(static fn() => $store->search('subject-a', 'project', 'Synthetic', 21), 'unbounded search limit rejected');
    rejects(static fn() => $store->search('../subject-a', 'project', 'Synthetic'), 'traversal-like identity rejected');
    rejects(static fn() => $store->create('subject-a', 'project', 'authority', 'Synthetic', 'synthetic_test', 'fixture://x'), 'invalid engram type rejected');
    rejects(static fn() => $store->create('subject-a', 'project', 'lesson', str_repeat('x', 4097), 'synthetic_test', 'fixture://x'), 'oversized body rejected');
    rejects(static fn() => $store->create('subject-a', 'project', 'lesson', 'Synthetic', 'untrusted_chat', 'fixture://x'), 'unapproved provenance rejected');
    $two = $store->revise('subject-a', 'project', $one['id'], 1, $one['revision_hash'],
        'Synthetic: verified new collaboration decision.', 'synthetic_test', 'fixture://two');
    check($two['revision'] === 2 && $two['revision_hash'] !== $one['revision_hash'], 'revision chain updated');
    check(count($store->search('subject-a', 'project', 'summarize')) === 0, 'old revision not presented as current');
    check(count($store->search('subject-a', 'project', 'verified new')) === 1, 'new revision searchable');
    rejects(static fn() => $store->revise('subject-a', 'project', $one['id'], 1, $one['revision_hash'],
        'Synthetic stale write', 'synthetic_test', 'fixture://stale'), 'stale revision denied');
    rejects(static fn() => $store->revise('subject-b', 'project', $one['id'], 2, $two['revision_hash'],
        'Synthetic forbidden update', 'synthetic_test', 'fixture://cross'), 'cross-subject revision denied');
    rejects(static fn() => $store->revise('subject-a', 'project', $one['id'], 2, str_repeat('0', 64),
        'Synthetic forged update', 'synthetic_test', 'fixture://bad'), 'wrong revision hash denied');
    $three = $store->revise('subject-a', 'project', $one['id'], 2, $two['revision_hash'],
        'Synthetic: withdraw this note.', 'synthetic_test', 'fixture://withdraw', true);
    check($three['revision'] === 3, 'withdrawal appends a new revision');
    check(count($store->search('subject-a', 'project', 'Synthetic')) === 0, 'withdrawn engram absent from current search');
    rejects(static fn() => $store->revise('subject-a', 'project', $one['id'], 3, $three['revision_hash'],
        'Synthetic: resurrect', 'synthetic_test', 'fixture://resurrect'), 'withdrawn note cannot be silently reactivated');

    $insecure = $root . '/insecure-db';
    mkdir($insecure, 0700);
    file_put_contents($insecure . '/engrams.sqlite', 'not-real-sqlite');
    chmod($insecure . '/engrams.sqlite', 0644);
    rejects(static fn() => new KiComEngramStore($insecure, $web), 'preexisting readable database rejected');
    unlink($insecure . '/engrams.sqlite');
    symlink($private . '/engrams.sqlite', $insecure . '/engrams.sqlite');
    rejects(static fn() => new KiComEngramStore($insecure, $web), 'database symlink rejected');
    check(($store->health()['quick_check'] ?? null) === 'ok', 'database remains healthy after negative tests');

    // Synthetic consistency test: backup includes the latest committed row.
    $backups = $root . '/backups';
    $restore = $root . '/restore';
    mkdir($backups, 0700);
    mkdir($restore, 0700);
    $store->create('subject-a', 'project', 'technical',
        'Synthetic: latest committed record.', 'synthetic_test', 'fixture://backup');
    rejects(static fn() => $store->backup($web, $web), 'backup inside webroot denied');
    $snapshot = $store->backup($backups, $web);
    $source = $backups . '/' . $snapshot['filename'];
    check($snapshot['revision_count'] >= 4, 'backup contains all committed revisions');
    check((fileperms($source) & 0077) === 0, 'backup permissions private');
    $result = KiComEngramStore::restore($source, $restore, $web, $snapshot['sha256']);
    check($result['restored'] === true, 'consistent private restore');
    $reopened = new KiComEngramStore($restore, $web);
    check(count($reopened->search('subject-a', 'project', 'latest committed record')) === 1,
        'restored content matches latest commit');
    rejects(static fn() => KiComEngramStore::restore($source, $restore, $web, $snapshot['sha256']),
        'existing database cannot be overwritten');
    rejects(static fn() => KiComEngramStore::restore($source, $root . '/private', $web, str_repeat('0', 64)),
        'mismatched independent digest rejected');

    $emptyRestore = $root . '/empty-restore';
    mkdir($emptyRestore, 0700);
    $backupLink = $backups . '/alias.sqlite';
    symlink($source, $backupLink);
    rejects(static fn() => KiComEngramStore::restore($backupLink, $emptyRestore, $web, $snapshot['sha256']),
        'symlink backup source denied');
    chmod($source, 0644);
    rejects(static fn() => KiComEngramStore::restore($source, $emptyRestore, $web, $snapshot['sha256']),
        'overpermissive backup source denied');
    chmod($source, 0600);
    $badDirectory = $root . '/open-backup';
    mkdir($badDirectory, 0755);
    rejects(static fn() => $store->backup($badDirectory, $web),
        'overpermissive backup directory denied');
    $backupLinkDir = $root . '/alias-backup-dir';
    symlink($backups, $backupLinkDir);
    rejects(static fn() => $store->backup($backupLinkDir, $web),
        'symlink backup directory denied');
    unset($reopened);


    $sidecarDir = $root . '/sidecar-denial';
    mkdir($sidecarDir, 0700);
    $outside = $root . '/harmless-target';
    file_put_contents($outside, 'synthetic');
    symlink($outside, $sidecarDir . '/engrams.sqlite-wal');
    rejects(static fn() => new KiComEngramStore($sidecarDir, $web),
        'preexisting WAL symlink denied before database open');
    unlink($sidecarDir . '/engrams.sqlite-wal');
    symlink($outside, $sidecarDir . '/engrams.sqlite-shm');
    rejects(static fn() => new KiComEngramStore($sidecarDir, $web),
        'preexisting SHM symlink denied before database open');
    unlink($sidecarDir . '/engrams.sqlite-shm');
    file_put_contents($sidecarDir . '/engrams.sqlite-wal', 'synthetic');
    chmod($sidecarDir . '/engrams.sqlite-wal', 0644);
    rejects(static fn() => new KiComEngramStore($sidecarDir, $web),
        'preexisting readable WAL file denied');
    unlink($sidecarDir . '/engrams.sqlite-wal');
    file_put_contents($sidecarDir . '/engrams.sqlite-shm', 'synthetic');
    chmod($sidecarDir . '/engrams.sqlite-shm', 0644);
    rejects(static fn() => new KiComEngramStore($sidecarDir, $web),
        'preexisting readable SHM file denied');
    unlink($sidecarDir . '/engrams.sqlite-shm');
    $sidecarStore = new KiComEngramStore($sidecarDir, $web);
    check(($sidecarStore->health()['quick_check'] ?? null) === 'ok',
        'safe storage opens after rejecting unsafe sidecars');
    unset($sidecarStore);

    chmod($sidecarDir, 0755);
    rejects(static fn() => (new KiComEngramStore($sidecarDir, $web)),
        'private directory mode downgrade prevents reopening');
    chmod($sidecarDir, 0700);
    $sidecarStore = new KiComEngramStore($sidecarDir, $web);
    chmod($sidecarDir, 0755);
    rejects(static fn() => $sidecarStore->health(),
        'live private store denies directory permissions downgrade');
    chmod($sidecarDir, 0700);
    check(($sidecarStore->health()['quick_check'] ?? null) === 'ok',
        'live store recovers after permissions restored');
    unset($sidecarStore);

    echo "KICOM_ENGRAM_TESTS_PASSED=$checks\n";
} finally {
    unset($store);
    recursiveRemove($root);
}

// Run the separate synthetic local-path probe suite through the existing Engram CI.
require __DIR__ . '/test-private-path-probe.php';
