<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramMirrorSet.php';

/**
 * Isolated DEV-only verification of a DETACHED Ed25519 signature over an
 * explicit, canonical, metadata-only manifest anchor. This class NEVER
 * creates a private key, signs real data, fetches a remote trust root,
 * promotes a generation, or grants an authorization capability.
 *
 * Operator-protected trust inputs (public key, store ID, minimum sequence,
 * trusted clock) must come from independently protected server configuration.
 * Never accept these inputs from memory content, HTTP request or same-account
 * writable mirror/manifest paths. A signature is not proof of data freshness
 * unless a monotonic minimum sequence is also independently anchored.
 */
final class KiComEngramAnchorVerifier
{
    /**
     * Verify receipt against its actual immutable manifest, without logging
     * paths, engram data or returning authority for writes/current promotion.
     * This checks a v2 parent digest matches the current manifest metadata,
     * but does NOT independently authenticate or fetch the parent generation.
     */
    public static function inspectSignedMirror(
        KiComEngramMirrorSet $mirrors,
        string $receiptJson, string $trustedPublicKeyHex,
        string $expectedStoreId, int $minimumSequence, int $trustedNow
    ): array {
        $anchor = self::verify(
            $receiptJson, $trustedPublicKeyHex, $expectedStoreId,
            $minimumSequence, $trustedNow
        );
        $status = $mirrors->inspect($anchor['manifest'], $anchor['manifest_sha256']);
        if (($status['manifest_format'] === 'engram-mirror-v1'
                && $anchor['parent_manifest_sha256'] !== null)
            || ($status['manifest_format'] === 'engram-mirror-v2'
                && ($anchor['parent_manifest_sha256'] === null
                    || !hash_equals($status['parent_manifest_sha256'],
                        $anchor['parent_manifest_sha256'])))) {
            throw new RuntimeException('Signed anchor parent metadata does not match manifest');
        }
        return [
            'signature_verified' => true,
            'state' => $status['state'],
            'verified_mirrors' => $status['verified_mirrors'],
            'sequence' => $anchor['sequence'],
            'manifest' => $anchor['manifest'],
            'manifest_sha256' => $anchor['manifest_sha256'],
        ];
    }

    public static function verify(
        string $receiptJson,
        string $trustedPublicKeyHex,
        string $expectedStoreId,
        int $minimumSequence,
        int $trustedNow
    ): array {
        if (strlen($receiptJson) < 80 || strlen($receiptJson) > 2048
            || $minimumSequence < 1 || $trustedNow < 1
            || !preg_match('/\A[a-z0-9][a-z0-9._:-]{2,63}\z/D', $expectedStoreId)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $trustedPublicKeyHex)
            || !function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Independent anchor trust configuration invalid');
        }
        try {
            $envelope = json_decode($receiptJson,true,8,JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Signed anchor receipt is not valid JSON');
        }
        if (!is_array($envelope)
            || array_keys($envelope) !== ['payload','signature']
            || !is_array($envelope['payload'])
            || !is_string($envelope['signature'])
            || !preg_match('/\A[a-f0-9]{128}\z/D', $envelope['signature'])) {
            throw new RuntimeException('Signed anchor envelope invalid');
        }
        $p = $envelope['payload'];
        if (array_keys($p) !== [
                'format','algorithm','context','store_id','sequence',
                'manifest','manifest_sha256','parent_manifest_sha256',
                'issued_at','expires_at'
            ]
            || $p['format'] !== 'engram-anchor-v1'
            || $p['algorithm'] !== 'Ed25519'
            || $p['context'] !== 'kicom-engram-private-mirror'
            || !is_string($p['store_id'])
            || !hash_equals($expectedStoreId, $p['store_id'])
            || !is_int($p['sequence'])
            || $p['sequence'] < $minimumSequence
            || !is_string($p['manifest'])
            || !preg_match('/\Amirror-[a-f0-9]{32}\.json\z/D', $p['manifest'])
            || !is_string($p['manifest_sha256'])
            || !preg_match('/\A[a-f0-9]{64}\z/D', $p['manifest_sha256'])
            || (!is_null($p['parent_manifest_sha256'])
                && (!is_string($p['parent_manifest_sha256'])
                    || !preg_match('/\A[a-f0-9]{64}\z/D', $p['parent_manifest_sha256'])))
            || !is_int($p['issued_at']) || $p['issued_at'] < 1
            || $p['issued_at'] > $trustedNow + 60
            || (!is_null($p['expires_at'])
                && (!is_int($p['expires_at'])
                    || $p['expires_at'] <= $p['issued_at']
                    || $trustedNow >= $p['expires_at']))) {
            throw new RuntimeException('Signed anchor scope, schema or freshness invalid');
        }
        $canonical = json_encode($p,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $pk = hex2bin($trustedPublicKeyHex);
        $signature = hex2bin($envelope['signature']);
        if ($pk === false || $signature === false
            || !sodium_crypto_sign_verify_detached($signature,$canonical,$pk)) {
            throw new RuntimeException('Independent anchor signature invalid');
        }
        return [
            'verified' => true,
            'manifest' => $p['manifest'],
            'manifest_sha256' => $p['manifest_sha256'],
            'parent_manifest_sha256' => $p['parent_manifest_sha256'],
            'sequence' => $p['sequence'],
        ];
    }
}
