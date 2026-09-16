<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/passkey-auth/PasskeyBridge.php';
require_once __DIR__.'/DevAuthAdapter.php';

/**
 * First-party browser flow for DEV authentication.
 *
 * The generic PasskeyBridge challenge format expects a Curve25519 recipient key
 * for encrypted handoff. DEV returns the credential directly to the same-origin
 * browser, so this flow creates a throwaway recipient key only to satisfy that
 * generic challenge envelope. No private key or DEV token leaves the browser flow.
 */
final class KiComDevAuthFlow
{
    private KiComPasskeyBridge $passkeys;
    private KiComDevAuthAdapter $adapter;

    public function __construct(KiComPasskeyBridge $passkeys, KiComDevAuthAdapter $adapter)
    {
        $this->passkeys = $passkeys;
        $this->adapter = $adapter;
    }

    public function begin(): array
    {
        if (!function_exists('sodium_crypto_box_keypair')) return ['ok'=>false,'code'=>'SODIUM_REQUIRED'];
        $kp = sodium_crypto_box_keypair();
        $pub = sodium_crypto_box_publickey($kp);
        $encoded = KiComPasskeyBridge::b64uEncode($pub);
        if (function_exists('sodium_memzero')) sodium_memzero($kp);
        $r = $this->passkeys->createAuthChallenge($encoded);
        if (empty($r['ok'])) return $r;
        return $r + ['flow'=>'same-origin-dev'];
    }

    public function options(string $challengeId): array
    {
        return $this->passkeys->assertionOptions($challengeId);
    }

    public function complete(string $challengeId, array $credential, string $label = 'KiCom DEV'): array
    {
        return $this->adapter->verifyAndIssue($challengeId, $credential, $label);
    }
}
