<?php
declare(strict_types=1);

/**
 * DEV-89: READ-ONLY verification of the independently authorized, persistent
 * private KiCom OAuth WRITE consent. Does not issue consent, create schema,
 * migrate data, mint tokens, or accept an MCP request as an approval.
 *
 * The host must supply the trusted PRIVATE OAuth SQLite connection and the
 * current server-issued OAuth token hash, not a caller-claimed fingerprint.
 * The first-party admin/passkey flow must separately create/revoke consent.
 */
final class KiComEngramPrivateWriteConsentReader
{
    private PDO $db;
    public function __construct(PDO $privateOAuthDb)
    {
        if($privateOAuthDb->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')
            throw new RuntimeException('PRIVATE_OAUTH_SQLITE_REQUIRED');
        $this->db=$privateOAuthDb;
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }

    /** Exact source-kind + owner + namespace + connector + TOKEN row + grant. */
    public function verify(array $claim,int $now):bool
    {
        if(array_keys($claim)!==['owner','namespace','token_fingerprint','source_kind','source_ref'])
            return false;
        foreach($claim as $part)if(!is_string($part)||$part==='')return false;
        if(!preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D',$claim['owner'])
           || !preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D',$claim['namespace'])
           || !preg_match('/\A[a-f0-9]{64}\z/D',$claim['token_fingerprint'])
           || !preg_match('/\Aconsent:[a-f0-9]{64}\z/D',$claim['source_ref'])
           || !in_array($claim['source_kind'],['explicit_user','approved_summary','verified_checkpoint'],true)
           || $now<1)return false;
        // An absent schema is a denied write, never an excuse to create a DB
        // from a bearer-authenticated MCP request.
        try {
            $q=$this->db->prepare('SELECT c.consent_ref,c.owner,c.namespace,c.connector_id,
                       c.owner_binding,c.credential_fingerprint,c.source_kind,c.approved_at
                  FROM mirage_oauth_write_consents c
                  INNER JOIN mirage_oauth_tokens t
                    ON t.token_hash=c.token_hash AND t.client_id=c.client_id
                   AND t.connector_id=c.connector_id
                   AND t.owner_binding=c.owner_binding
                   AND t.credential_fingerprint=c.credential_fingerprint
                  WHERE c.consent_ref=:receipt AND c.owner=:owner
                    AND c.namespace=:namespace AND c.source_kind=:kind
                    AND c.revoked_at IS NULL AND c.approved_at<=:now
                    AND t.token_hash=:token AND t.revoked=0
                    AND t.issued_at<=:now AND t.expires_at>:now
                    AND (t.scope=:writeonly OR t.scope=:both)
                  LIMIT 1');
            $q->execute([
                ':receipt'=>$claim['source_ref'],':owner'=>$claim['owner'],
                ':namespace'=>$claim['namespace'],':kind'=>$claim['source_kind'],
                ':token'=>$claim['token_fingerprint'],':now'=>$now,
                ':writeonly'=>'engram.write',':both'=>'engram.read engram.write',
            ]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)return false;
            // Current enrolled KiCom passkey owner/connector revocation MUST
            // additionally be rechecked by the original first-party host gate.
            return hash_equals(
                hash('sha256',$claim['owner']."\0".$row['credential_fingerprint']),
                $row['owner_binding']
            );
        } catch(Throwable) { return false; }
    }
}
