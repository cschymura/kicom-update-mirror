<?php
declare(strict_types=1);

require_once __DIR__ . '/KiComMailSecretStore.php';
require_once __DIR__ . '/KiComMailTransport.php';
require_once __DIR__ . '/KiComMailLoopback.php';

$tmp = sys_get_temp_dir() . '/kicom-mail-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);

$store = new KiComMailSecretStore($tmp, 'KICOM_MAIL_TEST_PASSWORD');
if ($store->hasSecret()) {
    fwrite(STDERR, "FAIL secret unexpectedly present\n"); exit(1);
}
try {
    $store->provision('test-value', false);
    fwrite(STDERR, "FAIL unauthorized provision accepted\n"); exit(1);
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'MAIL_SECRET_PROVISION_AUTH_REQUIRED') {
        throw $e;
    }
}
$result = $store->provision('test-value', true);
if (($result['secret_visible'] ?? true) !== false || !$store->hasSecret() || $store->read() !== 'test-value') {
    fwrite(STDERR, "FAIL secret store\n"); exit(1);
}
$status = $store->status();
if (($status['secret_visible'] ?? true) !== false || ($status['path_visible'] ?? true) !== false) {
    fwrite(STDERR, "FAIL secret metadata exposure\n"); exit(1);
}

$transportMethods = get_class_methods(KiComMailTransport::class);
foreach (['exec','shell','grantPermission','deleteHistory','disableTlsVerification'] as $bad) {
    if (in_array($bad, $transportMethods, true)) {
        fwrite(STDERR, "FAIL forbidden transport method {$bad}\n"); exit(1);
    }
}

$src = file_get_contents(__DIR__ . '/KiComMailTransport.php');
if (!is_string($src) || !str_contains($src, "'verify_peer' => true") || !str_contains($src, "'verify_peer_name' => true")) {
    fwrite(STDERR, "FAIL TLS verification invariant\n"); exit(1);
}
if (str_contains($src, "'allow_self_signed' => true")) {
    fwrite(STDERR, "FAIL self-signed TLS enabled\n"); exit(1);
}

@unlink($tmp . '/secrets/mail-password');
@rmdir($tmp . '/secrets');
@rmdir($tmp);

echo "OK KiCom mail runtime selftest\n";
