<?php
declare(strict_types=1);

/**
 * STAGING ONLY: verifies the integrity of a bounded, domain-separated,
 * Ed25519-signed laboratory attestation. This is NOT a Slack signature.
 *
 * The protected verifier public key is an input, not authenticated by this
 * class. The corresponding private key belongs to a separately protected,
 * as-yet-unimplemented staging observer. Signed statements describe that
 * observer's CLAIM, not verified Slack delivery or KiCom-runtime receipt.
 *
 * The verifier cannot dispatch, replay, reconcile, authorize, store secrets,
 * or promote a recorded observation to an independently proven external effect.
 */
final class KiComMembraneStagingAttestation
{
    private const ALLOWED_KINDS = [
        'REMOTE_EFFECT_REPORTED',
        'REMOTE_EFFECT_ABSENCE_REPORTED',
        'KICOM_ACK_REPORTED',
    ];

    private static function result(string $code, array $meta = []): array
    {
        return [
            'code' => $code,
            'inspection_only' => true,
            'signature_valid' => $code === 'SIGNED_STAGING_CLAIM_INTEGRITY_VERIFIED',
            'independent_anchor_authenticated_here' => false,
            'slack_provider_effect_verified' => false,
            'kicom_runtime_ack_verified' => false,
            'action_authorized' => false,
            'delivery_authorized' => false,
            'automatic_replay_allowed' => false,
            'claim_metadata' => $meta
        ];
    }

    /** Canonical serialization shared with the separate, future signer. */
    public static function signingBytes(array $claim): string
    {
        $keys = [
            'version', 'key_id', 'workspace_id', 'conversation_id',
            'event_hash', 'raw_hash', 'nonce_hash', 'observation_hash',
            'kind', 'observed_at'
        ];
        if (array_keys($claim) !== $keys) {
            throw new InvalidArgumentException('Canonical statement fields/order invalid');
        }
        $strings = array_slice($keys, 0, -1);
        foreach ($strings as $key) {
            if (!is_string($claim[$key]) || $claim[$key] === ''
                || strlen($claim[$key]) > 180) {
                throw new InvalidArgumentException('Canonical field invalid');
            }
        }
        if ($claim['version'] !== 'kicom-staging-attestation-v1'
            || !preg_match('/^[a-zA-Z0-9._-]{5,100}$/D', $claim['key_id'])
            || !preg_match('/^[a-zA-Z0-9._-]{5,100}$/D', $claim['workspace_id'])
            || !preg_match('/^[a-zA-Z0-9._-]{5,100}$/D', $claim['conversation_id'])
            || !in_array($claim['kind'], self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException('Unsupported staging attestation context');
        }
        foreach (['event_hash', 'raw_hash', 'nonce_hash', 'observation_hash'] as $key) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $claim[$key])) {
                throw new InvalidArgumentException('Hash field invalid');
            }
        }
        if (!is_int($claim['observed_at']) || $claim['observed_at'] <= 0) {
            throw new InvalidArgumentException('Timestamp invalid');
        }
        return "KiCom/StagingAttestation/1\n"
            .implode("\n", array_map(
                static fn(string $key): string => $key.'='.$claim[$key],
                $keys
            ))."\n";
    }

    /**
     * @param array<string,mixed> $expected Values read from an independently
     *     verified staging receipt and bound allowlist, NOT the Slack message.
     */
    public static function verify(
        array $claim,
        string $signatureBase64,
        string $publicKeyBase64,
        array $expected,
        int $now
    ): array {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return self::result('SODIUM_UNAVAILABLE');
        }
        try {
            $bytes = self::signingBytes($claim);
        } catch (Throwable $e) {
            return self::result('ATTESTATION_FORMAT_INVALID');
        }
        foreach (['key_id', 'workspace_id', 'conversation_id',
                  'event_hash', 'raw_hash', 'nonce_hash', 'observation_hash'] as $key) {
            if (!is_string($expected[$key] ?? null)
                || !hash_equals($expected[$key], $claim[$key])) {
                return self::result('ATTESTATION_CONTEXT_MISMATCH');
            }
        }
        if (!is_array($expected['allowed_kinds'] ?? null)
            || !in_array($claim['kind'], $expected['allowed_kinds'], true)) {
            return self::result('ATTESTATION_KIND_NOT_ALLOWED');
        }
        if ($now <= 0 || abs($now - $claim['observed_at']) > 300) {
            return self::result('ATTESTATION_TIME_WINDOW_INVALID');
        }
        $signature = base64_decode($signatureBase64, true);
        $publicKey = base64_decode($publicKeyBase64, true);
        if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return self::result('ATTESTATION_KEY_OR_SIGNATURE_INVALID');
        }
        try {
            if (!sodium_crypto_sign_verify_detached($signature, $bytes, $publicKey)) {
                return self::result('ATTESTATION_SIGNATURE_INVALID');
            }
        } catch (Throwable $e) {
            return self::result('ATTESTATION_SIGNATURE_INVALID');
        }
        return self::result('SIGNED_STAGING_CLAIM_INTEGRITY_VERIFIED', [
            'event_hash' => $claim['event_hash'],
            'raw_hash' => $claim['raw_hash'],
            'observation_hash' => $claim['observation_hash'],
            'kind' => $claim['kind']
        ]);
    }
}
