<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramAnchorVerifier.php';

/**
 * READ-ONLY isolated DEV verifier for an explicit ordered list of independently
 * signed, immutable SQLite snapshot generations. It never discovers, accepts,
 * repairs, deletes, promotes, or exports unknown local artifacts.
 *
 * Trust inputs (public key, store ID, expected current manifest and monotonic
 * minimum current sequence) MUST be provisioned outside the mirror filesystem.
 * The caller, not this class, must ensure the receipt list is COMPLETE for any
 * retention or full-history assurance; omission of an unrelated older root is
 * not detectable from a signed current head alone.
 */
final class KiComEngramSignedCatalog
{
    public static function inspect(
        KiComEngramMirrorSet $mirrors,
        array $signedReceipts,
        string $trustedPublicKey,
        string $expectedStoreId,
        int $minimumCurrentSequence,
        string $trustedCurrentManifest,
        int $trustedNow
    ): array {
        if (count($signedReceipts) < 1 || count($signedReceipts) > 128
            || array_keys($signedReceipts) !== range(0,count($signedReceipts)-1)
            || $minimumCurrentSequence < 1
            || !preg_match('/\Amirror-[a-f0-9]{32}\.json\z/D',$trustedCurrentManifest)) {
            throw new RuntimeException('Trusted signed catalog configuration missing or invalid');
        }
        $known = [];
        $lastSequence = 0;
        $degraded = 0;
        $unrecoverable = 0;
        $latest = null;
        foreach ($signedReceipts as $receipt) {
            if (!is_string($receipt)) {
                throw new RuntimeException('Signed catalog entry must be canonical receipt text');
            }
            // Historic parent receipts must remain verifiable without applying
            // the CURRENT monotonic floor to their older sequence numbers.
            $verified = KiComEngramAnchorVerifier::verify(
                $receipt,$trustedPublicKey,$expectedStoreId,1,$trustedNow
            );
            if ($verified['sequence'] <= $lastSequence
                || isset($known[$verified['manifest']])) {
                throw new RuntimeException('Duplicate or non-monotonic signed generation');
            }
            $metadata = $mirrors->inspect(
                $verified['manifest'],$verified['manifest_sha256']
            );
            if (($metadata['manifest_format'] === 'engram-mirror-v1'
                    && $verified['parent_manifest_sha256'] !== null)
                || ($metadata['manifest_format'] === 'engram-mirror-v2'
                    && ($verified['parent_manifest_sha256'] === null
                        || !hash_equals($metadata['parent_manifest_sha256'],
                            $verified['parent_manifest_sha256'])))) {
                throw new RuntimeException('Signed catalog lineage metadata mismatch');
            }
            if ($metadata['manifest_format'] === 'engram-mirror-v2') {
                $parentName = $metadata['parent_manifest'];
                if (!is_string($parentName)
                    || !isset($known[$parentName])
                    || !hash_equals($known[$parentName]['sha256'],
                        $metadata['parent_manifest_sha256'])) {
                    throw new RuntimeException('V2 parent must have an earlier independently signed receipt');
                }
            }
            $known[$verified['manifest']] = [
                'sha256'=>$verified['manifest_sha256'],
                'sequence'=>$verified['sequence'],
            ];
            $lastSequence = $verified['sequence'];
            $degraded += $metadata['state'] === 'degraded' ? 1 : 0;
            $unrecoverable += $metadata['state'] === 'unrecoverable' ? 1 : 0;
            $latest = [
                'manifest'=>$verified['manifest'],
                'sha256'=>$verified['manifest_sha256'],
                'sequence'=>$verified['sequence'],
                'state'=>$metadata['state'],
                'verified_mirrors'=>$metadata['verified_mirrors'],
            ];
        }
        if ($latest === null
            || $latest['manifest'] !== $trustedCurrentManifest
            || $latest['sequence'] < $minimumCurrentSequence) {
            throw new RuntimeException('Catalog head is not the independently trusted current generation');
        }
        return [
            'signed_lineage_verified'=>true,
            'verified_generations'=>count($known),
            'current_sequence'=>$latest['sequence'],
            'current_state'=>$latest['state'],
            'current_verified_mirrors'=>$latest['verified_mirrors'],
            'degraded_generations'=>$degraded,
            'unrecoverable_generations'=>$unrecoverable,
            'operator_review_required'=>($degraded+$unrecoverable)>0,
            'auto_recovery_permitted'=>false,
            'complete_replica_inventory_proven'=>false,
        ];
    }
}
