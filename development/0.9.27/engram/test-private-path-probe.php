<?php
declare(strict_types=1);
require __DIR__ . '/KiComEngramPrivatePathProbe.php';

$passed = 0;
function assertProbe(bool $ok, string $label): void {
    global $passed;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $passed++;
    echo 'PASS ' . $label . "\n";
}
function denyProbe(callable $call, string $label): void {
    $denied = false;
    try { $call(); } catch (RuntimeException $e) { $denied = true; }
    assertProbe($denied, $label);
}
function eraseProbeTree(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') { eraseProbeTree($path . '/' . $name); }
    }
    rmdir($path);
}
$root = sys_get_temp_dir() . '/engram-probe-synthetic-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$web = $root . '/web';
$private = $root . '/private';
$data = $private . '/data';
$backups = $private . '/backups';
mkdir($web, 0755);
mkdir($private, 0700);
mkdir($data, 0700);
mkdir($backups, 0700);
try {
    $out = KiComEngramPrivatePathProbe::run($data, $backups, $web);
    assertProbe($out['private_paths_checked'] && $out['synthetic_rw_data']
        && $out['synthetic_rw_backups'] && $out['public_http_exposure_verified'] === false,
        'internal synthetic read/write with explicit HTTP exposure limitation');
    assertProbe(scandir($data) === ['.', '..'] && scandir($backups) === ['.', '..'],
        'private probe leaves no test file behind');
    denyProbe(static fn() => KiComEngramPrivatePathProbe::run($web, $backups, $web),
        'web document root cannot be used as private data');
    $insideWeb = $web . '/data';
    mkdir($insideWeb, 0700);
    denyProbe(static fn() => KiComEngramPrivatePathProbe::run($insideWeb, $backups, $web),
        'nested webroot path rejected');
    chmod($data, 0755);
    denyProbe(static fn() => KiComEngramPrivatePathProbe::run($data, $backups, $web),
        'readable private data directory rejected');
    chmod($data, 0700);
    chmod($backups, 0755);
    denyProbe(static fn() => KiComEngramPrivatePathProbe::run($data, $backups, $web),
        'readable backup directory rejected');
    chmod($backups, 0700);
    chmod($private, 0755);
    denyProbe(static fn() => KiComEngramPrivatePathProbe::run($data, $backups, $web),
        'readable private parent directory rejected');
    chmod($private, 0700);
    $alias = $root . '/alias';
    symlink($private, $alias);
    denyProbe(static fn() => KiComEngramPrivatePathProbe::run($alias . '/data', $backups, $web),
        'symlinked private path component rejected');
    $foreign = $root . '/foreign';
    mkdir($foreign, 0700);
    $foreignBackups = $foreign . '/backups';
    mkdir($foreignBackups, 0700);
    denyProbe(static fn() => KiComEngramPrivatePathProbe::run($data, $foreignBackups, $web),
        'unrelated backup directory rejected');
    denyProbe(static fn() => KiComEngramPrivatePathProbe::run($data, $backups, $root . '/missing-web'),
        'missing webroot fails closed');
    assertProbe(KiComEngramPrivatePathProbe::run($data, $backups, $web)['synthetic_rw_backups'],
        'valid private permissions restore probe capability');
    echo "KICOM_ENGRAM_PATH_PROBE_TESTS_PASSED=$passed\n";
} finally {
    eraseProbeTree($root);
}
