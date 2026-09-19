<?php
declare(strict_types=1);
/**
 * LAB-ONLY candidate lineage receipt. Invoke ONLY with a disposable
 * /tmp/kicom-membrane-os-<runid> test root. Not native KiCom GENOME_STATUS,
 * never external permission, and never a credential/grant/deployment API.
 */
final class KiComChildLineage
{
    public static function proofBytes(string $nonce, string $parentPublic, string $childPublic, string $packageSha): string
    {
        return "kicom-child-genesis-lab-v1\n".$nonce."\n".$parentPublic."\n".$childPublic."\n".$packageSha."\n";
    }

    public static function checkProof(
        string $nonce, string $parentPublic, string $childPublic, string $packageSha, string $signature
    ): array {
        $valid = strlen($nonce)===64 && ctype_xdigit($nonce)
            && preg_match('/^[A-Za-z0-9+\/]{43}=$/D', $childPublic)
            && preg_match('/^[A-Za-z0-9+\/]{43}=$/D', $parentPublic)
            && preg_match('/^[a-f0-9]{64}$/D', $packageSha)
            && $parentPublic!==$childPublic;
        $rawKey=base64_decode($childPublic,true);
        $rawSignature=base64_decode($signature,true);
        $passed=$valid && is_string($rawKey) && strlen($rawKey)===SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && is_string($rawSignature) && strlen($rawSignature)===SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached(
                $rawSignature,
                self::proofBytes($nonce,$parentPublic,$childPublic,$packageSha),$rawKey);
        return [
            'code'=>$passed?'DAUGHTER_KEY_POSSESSION_CONFIRMED_LAB':'DAUGHTER_KEY_PROOF_REJECTED',
            'proof_valid'=>$passed,
            'native_genome_bound'=>false,
            'runtime_ack_verified'=>false,
            'external_action_authorized'=>false,
            'deployment_authorized'=>false
        ];
    }

    public static function candidateRecord(
        string $nonce, string $parentPublic, string $childPublic, string $packageSha,
        string $signature, string $childLabel, string $sourceGenome
    ): array {
        $proof=self::checkProof($nonce,$parentPublic,$childPublic,$packageSha,$signature);
        if (!$proof['proof_valid'] || !preg_match('/^[a-z][a-z0-9-]{2,30}$/D',$childLabel)
            || $sourceGenome!=='kicom-0.9.26-g25r3') {
            return ['ok'=>false,'code'=>'LAB_LINEAGE_CANDIDATE_REJECTED','native_genome_bound'=>false,
                'external_action_authorized'=>false];
        }
        return [
            'ok'=>true, 'code'=>'LAB_LINEAGE_CANDIDATE_ONLY',
            'format'=>'kicom-reproduction-lab-lineage-v1',
            'source_genome'=>$sourceGenome,
            'source_release_sha256'=>$packageSha,
            'parent_public_fingerprint'=>hash('sha256',base64_decode($parentPublic,true)),
            'child_public_fingerprint'=>hash('sha256',base64_decode($childPublic,true)),
            'child_label'=>$childLabel,
            'nonce_sha256'=>hash('sha256',$nonce),
            'proof_sha256'=>hash('sha256',$signature),
            'native_genome_bound'=>false,
            'recovery_bound'=>false,
            'independent_host_qualified'=>false,
            'deployment_authorized'=>false,
            'external_action_authorized'=>false,
        ];
    }
}
