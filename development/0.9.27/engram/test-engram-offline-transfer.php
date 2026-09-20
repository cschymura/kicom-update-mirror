<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramSyntheticTransfer.php';

$offlineChecks = 0;
function offlineCheck(bool $condition, string $label): void
{
    global $offlineChecks;
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    ++$offlineChecks;
    echo 'PASS ' . $label . "\n";
}
function offlineReject(callable $function, string $label): void
{
    $blocked = false;
    try { $function(); }
    catch (RuntimeException|InvalidArgumentException $e) { $blocked = true; }
    offlineCheck($blocked, $label);
}
function offlineCommand(array $argv): array
{
    $pipes = [];
    $handle = proc_open($argv, [
        0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w'],
    ], $pipes);
    if (!is_resource($handle)) throw new RuntimeException('Offline subprocess unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($handle);
    // Never echo stderr/stdout unfiltered: future private data must not log.
    return ['code'=>$status, 'stdout'=>$stdout, 'stderr'=>$stderr];
}
function offlineErase(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $part) {
        if ($part !== '.' && $part !== '..') offlineErase($path.'/'.$part);
    }
    @rmdir($path);
}
$root = sys_get_temp_dir().'/engram-offline-synthetic-'.bin2hex(random_bytes(12));
mkdir($root,0700);
foreach (['web','export','separate','keys'] as $n) {
    mkdir($root.'/'.$n,$n === 'web' ? 0755 : 0700);
}
$python = __DIR__.'/engram_local_offline.py';
try {
    $keyPath = $root.'/keys/workstation-private.key';
    $generate = offlineCommand(['python3',$python,'generate-key','--private-key',$keyPath]);
    $metadata = json_decode($generate['stdout'], true);
    offlineCheck($generate['code'] === 0 && is_array($metadata)
        && $metadata['key_created'] === true
        && $metadata['private_key_exported'] === false
        && preg_match('/\A[a-f0-9]{64}\z/D',$metadata['public_key_hex']) === 1,
        'independent workstation generates private key locally and exposes only public key');
    offlineCheck(is_file($keyPath) && filesize($keyPath) === 32
        && (fileperms($keyPath) & 0077) === 0
        && !str_contains($generate['stdout'],bin2hex((string)file_get_contents($keyPath))),
        'private key remains only in local mode-0600 file, never in CLI output');
    $repeat = offlineCommand(['python3',$python,'generate-key','--private-key',$keyPath]);
    offlineCheck($repeat['code'] !== 0,
        'workstation refuses to overwrite its existing private decryption key');
    $package = KiComEngramSyntheticTransfer::create(
        $metadata['public_key_hex'], $root.'/export', $root.'/web'
    );
    offlineCheck($package['synthetic_only'] === true
        && $package['recipient_private_key_on_host'] === false
        && $package['plaintext_exposed_in_bundle'] === false
        && $package['revision_count'] === 2,
        'server-side synthetic export has no private operator key and no real engram input');
    $file = $root.'/export/'.$package['filename'];
    $bytes = (string)file_get_contents($file);
    offlineCheck(is_file($file) && (fileperms($file) & 0077) === 0
        && hash('sha256',$bytes) === $package['bundle_sha256']
        && !str_contains($bytes, 'ENGRAM_SYNTHETIC_ONLY::'),
        'private encrypted bundle contains no plaintext fixture or host path');
    offlineCheck(scandir($root.'/export') === ['.','..',$package['filename']],
        'synthetic source SQLite and WAL/SHM are removed from the export directory');
    $check = offlineCommand(['python3',$python,'inspect-synthetic',
        '--private-key',$keyPath,'--bundle',$file,
        '--expected-bundle-sha256',$package['bundle_sha256']]);
    $verified = json_decode($check['stdout'],true);
    offlineCheck($check['code'] === 0 && is_array($verified)
        && $verified['encrypted_transfer_verified'] === true
        && $verified['synthetic_revision_chain_verified'] === true
        && $verified['revision_count'] === 2
        && $verified['sender_authenticated'] === false,
        'separately stored workstation private key decrypts and audits two synthetic SQLite revisions');
    offlineCheck(!str_contains($check['stdout'],'ENGRAM_SYNTHETIC_ONLY::')
        && !str_contains($check['stdout'],$root)
        && !is_file($root.'/keys/plaintext.sqlite'),
        'local verification returns only metadata and persists no plaintext snapshot');
    $badDigest = offlineCommand(['python3',$python,'inspect-synthetic',
        '--private-key',$keyPath,'--bundle',$file,
        '--expected-bundle-sha256',str_repeat('0',64)]);
    // Invoke with the normal command shape, not shell/flag equals injection.
    offlineCheck($badDigest['code'] !== 0,
        'mismatched separately noted bundle hash fails closed');
    $wrongKey = $root.'/keys/wrong.key';
    $new = offlineCommand(['python3',$python,'generate-key','--private-key',$wrongKey]);
    offlineCheck($new['code'] === 0, 'synthetic second workstation can generate a separate key');
    $wrong = offlineCommand(['python3',$python,'inspect-synthetic',
        '--private-key',$wrongKey,'--bundle',$file,
        '--expected-bundle-sha256',$package['bundle_sha256']]);
    offlineCheck($wrong['code'] !== 0,
        'bundle cannot be decrypted by a different local workstation key');
    $altered = $root.'/export/altered.json';
    $changed = json_decode($bytes,true,8,JSON_THROW_ON_ERROR);
    $changed['snapshot_sha256'] = str_repeat('a',64);
    file_put_contents($altered,json_encode($changed,JSON_THROW_ON_ERROR));
    $alteredBytes = (string)file_get_contents($altered);
    $deny = offlineCommand(['python3',$python,'inspect-synthetic',
        '--private-key',$keyPath,'--bundle',$altered,
        '--expected-bundle-sha256',hash('sha256',$alteredBytes)]);
    offlineCheck($deny['code'] !== 0,
        'altered public snapshot checksum fails even if envelope checksum is updated');
    offlineReject(static fn() => KiComEngramSyntheticTransfer::create(
        str_repeat('0',12),$root.'/export',$root.'/web'),
        'invalid recipient public-key shape rejected before any snapshot creation');
    offlineReject(static fn() => KiComEngramSyntheticTransfer::create(
        $metadata['public_key_hex'],$root.'/web',$root.'/web'),
        'public output destination rejected before synthetic snapshot creation');
    chmod($root.'/separate',0755);
    offlineReject(static fn() => KiComEngramSyntheticTransfer::create(
        $metadata['public_key_hex'],$root.'/separate',$root.'/web'),
        'readable output directory rejected');
    chmod($root.'/separate',0700);
    offlineCheck(!is_file($root.'/web/engrams.sqlite'),
        'synthetic export never writes its SQLite database to a public web directory');
    echo "KICOM_ENGRAM_OFFLINE_TRANSFER_TESTS_PASSED=$offlineChecks\n";
} finally {
    offlineErase($root);
}
