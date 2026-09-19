<?php
declare(strict_types=1);
/**
 * GITHUB CI LAB FIXTURE ONLY. Not a KiCom runtime endpoint, Slack adapter,
 * trusted observer, or authorization/signing service for real messages.
 *
 * This fixture uses one fixed synthetic attestation. The verifier reads a
 * root-pinned public key and root-owned expected context, NOT caller-supplied
 * public keys, environment claims, or data in the signed JSON itself.
 */
require_once __DIR__.'/KiComMembraneStagingAttestation.php';

$root = $argv[2] ?? '';
if (!preg_match('~^/tmp/kicom-membrane-os-[0-9]+$~D', $root)
    || is_link($root) || !is_dir($root)) {
    throw new RuntimeException('Only isolated GitHub Linux fixture root permitted');
}
$policy = $root.'/policy';
$privatePath = $policy.'/staging-private.key';
$publicPath = $root.'/pinned-public.key';
$expectedPath = $root.'/trusted-staging-context.json';
$now = 1789826700;
$claim = [
    'version'=>'kicom-staging-attestation-v1',
    'key_id'=>'stage-membrane-ci',
    'workspace_id'=>'T_STAGE_ISOLATED',
    'conversation_id'=>'G_STAGE_ONLY',
    'event_hash'=>hash('sha256','fixture-event'),
    'raw_hash'=>hash('sha256','fixture-payload'),
    'nonce_hash'=>hash('sha256','fixture-challenge'),
    'observation_hash'=>hash('sha256','fixture-observation'),
    'kind'=>'REMOTE_EFFECT_REPORTED',
    'observed_at'=>$now
];
$expected = [
    'key_id'=>$claim['key_id'],
    'workspace_id'=>$claim['workspace_id'],
    'conversation_id'=>$claim['conversation_id'],
    'event_hash'=>$claim['event_hash'],
    'raw_hash'=>$claim['raw_hash'],
    'nonce_hash'=>$claim['nonce_hash'],
    'observation_hash'=>$claim['observation_hash'],
    'allowed_kinds'=>['REMOTE_EFFECT_REPORTED']
];
$mode = $argv[1] ?? '';
if ($mode === 'initialize') {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('Only root may create pinned laboratory trust root');
    }
    foreach ([$privatePath, $publicPath, $expectedPath] as $path) {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Refuse to replace a previous laboratory key or policy');
        }
    }
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);
    $old = umask(0077);
    try {
        if (file_put_contents($privatePath, $secret, LOCK_EX) !== strlen($secret)
            || !chown($privatePath, 'kicom_membrane_ci')
            || !chmod($privatePath, 0400)
            || file_put_contents($publicPath, base64_encode($public), LOCK_EX) === false
            || !chmod($publicPath, 0444)
            || file_put_contents($expectedPath, json_encode($expected, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || !chmod($expectedPath, 0444)) {
            throw new RuntimeException('Cannot establish independent staging fixtures');
        }
    } finally {
        umask($old);
    }
    echo "STAGING_TEST_TRUST_ROOT_INITIALIZED\n";
    exit(0);
}
if ($mode === 'sign') {
    if (!function_exists('posix_geteuid')
        || posix_geteuid() !== posix_getpwnam('kicom_membrane_ci')['uid']) {
        throw new RuntimeException('Isolated signer principal required');
    }
    $secret = @file_get_contents($privatePath);
    if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('Protected signing key inaccessible');
    }
    $signature = sodium_crypto_sign_detached(
        KiComMembraneStagingAttestation::signingBytes($claim), $secret
    );
    echo json_encode(['claim'=>$claim,'signature'=>base64_encode($signature)], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
if ($mode === 'verify') {
    if (!function_exists('posix_geteuid')
        || posix_geteuid() !== posix_getpwnam('kicom_membrane_ci')['uid']) {
        throw new RuntimeException('Protected verifier principal required');
    }
    $file = $argv[3] ?? '';
    if (!preg_match('~^/tmp/kicom-membrane-os-[0-9]+/(?:signed|forged)\.json$~D', $file)
        || is_link($file)) {
        throw new RuntimeException('Only explicit isolated observation fixture permitted');
    }
    $json = @file_get_contents($file);
    $public = @file_get_contents($publicPath);
    $contextJson = @file_get_contents($expectedPath);
    if (!is_string($json) || !is_string($public) || !is_string($contextJson)) {
        throw new RuntimeException('Protected test evidence unavailable');
    }
    $packet = json_decode($json,true,64,JSON_THROW_ON_ERROR);
    $expectedPinned = json_decode($contextJson,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($packet) || !is_array($expectedPinned)) {
        throw new RuntimeException('Invalid attestation test packet');
    }
    $result = KiComMembraneStagingAttestation::verify(
        $packet['claim'] ?? [],
        (string)($packet['signature'] ?? ''),
        $public,
        $expectedPinned,
        $now
    );
    // Never grant action even when the pinned signature succeeds.
    if (($result['action_authorized'] ?? true) !== false
        || ($result['delivery_authorized'] ?? true) !== false
        || ($result['automatic_replay_allowed'] ?? true) !== false
        || ($result['independent_anchor_authenticated_here'] ?? true) !== false) {
        throw new RuntimeException('An attestation cannot grant runtime authority');
    }
    echo $result['code']."\n";
    exit($result['signature_valid'] ? 0 : 8);
}
throw new InvalidArgumentException('Unsupported local fixture operation');
