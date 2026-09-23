<?php
declare(strict_types=1);
/**
 * First-party WRITE eligibility for the original native KiCom MCP host.
 * The caller must FIRST validate an actually issued combined-scope bearer
 * through the native KiComEngramOAuthTransactions::verifyCombinedScope.
 * Current owner registry, private OAuth row and separate consent must ALL
 * agree. This module issues no scope, approval, token or schema.
 */
final class KiComEngramNativeMcpWriteGate {
    public static function approved(
        PDO $oauthDb,string $bearer,array $combinedIdentity,
        callable $currentOwners,int $now
    ):?array {
        if($oauthDb->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'
            || !preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$bearer)
            || ($combinedIdentity['authenticated']??null)!==true
            || !is_string($combinedIdentity['credential_fingerprint']??null)
            || !preg_match('/\A[a-f0-9]{64}\z/D',$combinedIdentity['credential_fingerprint'])
            || !is_string($combinedIdentity['owner_binding']??null)
            || !is_string($combinedIdentity['connector_id']??null)
            || !is_string($combinedIdentity['host_evidence_id']??null)
            || $now<1)return null;
        $fp=$combinedIdentity['credential_fingerprint'];
        $binding=hash('sha256',"mirage-owner\0".$fp);
        if(!hash_equals($binding,$combinedIdentity['owner_binding']))return null;
        try {
            $owner=$currentOwners($fp);
            if(!is_array($owner)||($owner['enabled']??null)!==true
                || ($owner['subject']??null)!=='mirage-owner'
                || ($owner['credential_fingerprint']??null)!==$fp
                || ($owner['namespaces']??null)!==['project']
                || !is_array($owner['engram_rights']??null)
                || !in_array('engram.read',$owner['engram_rights'],true)
                || !in_array('engram.write',$owner['engram_rights'],true))return null;
            $q=$oauthDb->prepare('SELECT c.consent_ref,c.source_kind FROM mirage_oauth_write_consents c
                INNER JOIN mirage_oauth_tokens t ON
                    t.token_hash=c.token_hash AND t.client_id=c.client_id
                    AND t.connector_id=c.connector_id
                    AND t.owner_binding=c.owner_binding
                    AND t.credential_fingerprint=c.credential_fingerprint
                WHERE t.token_hash=:token AND t.scope=:scope AND t.revoked=0
                    AND t.issued_at<=:now_issued AND t.expires_at>:now_expired
                    AND t.resource=:resource AND t.host_evidence_id=:host
                    AND t.connector_id=:connector AND t.owner_binding=:binding
                    AND t.credential_fingerprint=:fingerprint
                    AND c.owner=:owner AND c.namespace=:namespace
                    AND c.revoked_at IS NULL AND c.approved_at<=:now_consent
                LIMIT 2');
            $q->execute([
                ':token'=>hash('sha256',$bearer),
                ':scope'=>'engram.read engram.write',
                ':now_issued'=>$now,':now_expired'=>$now,':now_consent'=>$now,
                ':resource'=>'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP',
                ':host'=>$combinedIdentity['host_evidence_id'],
                ':connector'=>$combinedIdentity['connector_id'],
                ':binding'=>$binding,':fingerprint'=>$fp,
                ':owner'=>'mirage-owner',':namespace'=>'project'
            ]);
            $first=$q->fetch(PDO::FETCH_ASSOC);
            $second=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($first)||$second!==false
                || !is_string($first['consent_ref']??null)
                || !preg_match('/\Aconsent:[a-f0-9]{64}\z/D',$first['consent_ref'])
                || !in_array($first['source_kind']??null,
                    ['explicit_user','approved_summary','verified_checkpoint'],true))return null;
            return ['source_kind'=>$first['source_kind'],
                'source_ref'=>$first['consent_ref']];
        }catch(Throwable){return null;}
    }
}
