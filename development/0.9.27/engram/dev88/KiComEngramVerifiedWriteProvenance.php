<?php
declare(strict_types=1);

/**
 * DEV-88. Provenance is a first-party, server-verified authorization attribute,
 * NEVER an MCP argument. This validator does not itself issue consent receipts:
 * production must source them from KiCom's genuine authenticated consent ledger.
 */
final class KiComEngramVerifiedWriteProvenance
{
    /** @return array{source_kind:string,source_ref:string} */
    public static function resolve(array $oauth,?callable $trustedReceiptLookup=null):array
    {
        if (($oauth['authenticated']??null)!==true
            || !isset($oauth['scopes']) || !is_array($oauth['scopes'])
            || !in_array('engram.write',$oauth['scopes'],true)) {
            throw new RuntimeException('UNAUTHORIZED_PROVENANCE');
        }
        $p=$oauth['server_provenance']??null;
        if (!is_array($p))throw new RuntimeException('MISSING_SERVER_PROVENANCE');
        $keys=array_keys($p);sort($keys,SORT_STRING);
        $required=['source_kind','source_ref','verified','owner','namespace','token_fingerprint'];
        sort($required,SORT_STRING);
        if ($keys!==$required || $p['verified']!==true
            || !is_string($p['source_kind'])
            || !is_string($p['source_ref'])
            || !is_string($p['owner']) || !is_string($p['namespace'])
            || !is_string($p['token_fingerprint'])
            || !is_string($oauth['owner']??null)
            || !is_string($oauth['namespace']??null)
            || !is_string($oauth['token_fingerprint']??null)
            || !hash_equals($oauth['owner'],$p['owner'])
            || !hash_equals($oauth['namespace'],$p['namespace'])
            || !hash_equals($oauth['token_fingerprint'],$p['token_fingerprint']))
            throw new RuntimeException('PROVENANCE_BOUNDARY_DENIED');
        $kind=$p['source_kind'];
        $ref=$p['source_ref'];
        if ($kind==='synthetic_test') {
            // No synthetic provenance is ever silently assigned to real records.
            if (($oauth['synthetic_environment']??false)!==true
                || !preg_match('/\Adev-test:[a-zA-Z0-9._:-]{8,120}\z/D',$ref))
                throw new RuntimeException('SYNTHETIC_PROVENANCE_ONLY_IN_TEST');
        } elseif (in_array($kind,['explicit_user','approved_summary','verified_checkpoint'],true)) {
            // Opaque, first-party consent receipt; NOT a supplied user text or URL.
            // Upstream MUST prove the consent receipt belongs to this owner+scope.
            if (($oauth['synthetic_environment']??false)===true
                || !preg_match('/\Aconsent:[a-f0-9]{64}\z/D',$ref))
                throw new RuntimeException('MISSING_REAL_CONSENT_RECEIPT');
            // A plausible consent:<hash> and 'verified=true' alone are NOT consent.
            // Callback MUST be supplied by the authenticated first-party host,
            // bound to an independent PRIVATE database lookup, not MCP arguments.
            if($trustedReceiptLookup===null
                || $trustedReceiptLookup([
                    'owner'=>$p['owner'],
                    'namespace'=>$p['namespace'],
                    'token_fingerprint'=>$p['token_fingerprint'],
                    'source_kind'=>$kind,
                    'source_ref'=>$ref,
                ])!==true)throw new RuntimeException('UNVERIFIED_REAL_CONSENT_RECEIPT');
        } else {
            throw new RuntimeException('UNSUPPORTED_PROVENANCE');
        }
        return ['source_kind'=>$kind,'source_ref'=>$ref];
    }
}
