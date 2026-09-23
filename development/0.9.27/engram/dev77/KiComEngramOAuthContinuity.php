<?php
declare(strict_types=1);

/**
 * DEV-77: ONE bounded OAuth continuity correction for the integrated release candidate.
 * Rationale: current KiCom read access tokens expire after one hour and the existing
 * transaction core advertises/implements authorization_code only. A later/new ChatGPT
 * instance can therefore legitimately receive 401 and be forced through authorization
 * again. This component adds server-side rotating refresh continuity without widening
 * engram.read to engram.write and without changing ChatGPT platform controls.
 *
 * DEV component only. Caller must have already verified the original access token and
 * must derive all bindings from the private OAuth DB/server configuration, never HTTP.
 */
final class KiComEngramOAuthContinuity
{
    private const READ_SCOPE='engram.read';
    private const RESOURCE='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
    private const ACCESS_TTL=3600;
    private const REFRESH_TTL=604800;

    public static function install(PDO $db):void
    {
        self::sqlite($db);
        $db->exec('CREATE TABLE IF NOT EXISTS mirage_oauth_refresh_tokens (
          refresh_hash TEXT PRIMARY KEY,
          client_id TEXT NOT NULL, connector_id TEXT NOT NULL,
          host_evidence_id TEXT NOT NULL, resource TEXT NOT NULL,
          scope TEXT NOT NULL, owner_binding TEXT NOT NULL,
          credential_fingerprint TEXT NOT NULL,
          issued_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
          consumed INTEGER NOT NULL DEFAULT 0, revoked INTEGER NOT NULL DEFAULT 0,
          parent_access_hash TEXT NOT NULL UNIQUE)');
    }

    /** Mint once from a currently verified READ access token row. */
    public static function mintForVerifiedRead(PDO $db,string $accessToken,int $now):array
    {
        self::sqlite($db); // Schema was installed by explicit operator action, never token HTTP.
        if(!self::secretFormat($accessToken))self::denied();
        $accessHash=hash('sha256',$accessToken);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $q=$db->prepare('SELECT * FROM mirage_oauth_tokens WHERE token_hash=?');
            $q->execute([$accessHash]); $r=$q->fetch(PDO::FETCH_ASSOC);
            if(!$r || (int)$r['revoked']!==0 || (int)$r['issued_at']>$now
              || (int)$r['expires_at']<=$now || $r['scope']!==self::READ_SCOPE
              || $r['resource']!==self::RESOURCE || !self::hex64($r['owner_binding'])
              || !self::hex64($r['credential_fingerprint'])) self::denied();
            $refresh=self::secret();
            $q=$db->prepare('INSERT INTO mirage_oauth_refresh_tokens
              (refresh_hash,client_id,connector_id,host_evidence_id,resource,scope,
               owner_binding,credential_fingerprint,issued_at,expires_at,parent_access_hash)
              VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([hash('sha256',$refresh),$r['client_id'],$r['connector_id'],
              $r['host_evidence_id'],self::RESOURCE,self::READ_SCOPE,$r['owner_binding'],
              $r['credential_fingerprint'],$now,$now+self::REFRESH_TTL,$accessHash]);
            $db->exec('COMMIT');
            return ['refresh_token'=>$refresh,'refresh_expires_in'=>self::REFRESH_TTL,
              'scope'=>self::READ_SCOPE];
        } catch(Throwable $e) { if($db->inTransaction())$db->exec('ROLLBACK'); throw $e; }
    }

    /** Single-use refresh rotation. Never creates or upgrades write scope. */
    public static function rotate(PDO $db,string $refreshToken,array $trustedClient,int $now):array
    {
        self::sqlite($db); // Schema was installed by explicit operator action, never token HTTP.
        if(!self::secretFormat($refreshToken))self::denied();
        foreach(['client_id','connector_id','host_evidence_id'] as $k)
            if(!isset($trustedClient[$k])||!is_string($trustedClient[$k])||$trustedClient[$k]==='')self::denied();
        $hash=hash('sha256',$refreshToken);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $q=$db->prepare('SELECT * FROM mirage_oauth_refresh_tokens WHERE refresh_hash=?');
            $q->execute([$hash]); $r=$q->fetch(PDO::FETCH_ASSOC);
            if(!$r || (int)$r['consumed']!==0 || (int)$r['revoked']!==0
              || (int)$r['issued_at']>$now || (int)$r['expires_at']<=$now
              || $r['scope']!==self::READ_SCOPE || $r['resource']!==self::RESOURCE
              || $r['client_id']!==$trustedClient['client_id']
              || $r['connector_id']!==$trustedClient['connector_id']
              || $r['host_evidence_id']!==$trustedClient['host_evidence_id']
              || !self::hex64($r['owner_binding']) || !self::hex64($r['credential_fingerprint'])) self::denied();
            // Reject a revoked, deleted or rebound parent access-token row even
            // when the opaque refresh token remains unexpired.
            $parent=$db->prepare('SELECT revoked,client_id,connector_id,host_evidence_id,
                          owner_binding,credential_fingerprint,scope
                          FROM mirage_oauth_tokens WHERE token_hash=?');
            $parent->execute([$r['parent_access_hash']]);
            $prior=$parent->fetch(PDO::FETCH_ASSOC);
            if(!$prior || (int)$prior['revoked']!==0
               || $prior['client_id']!==$r['client_id']
               || $prior['connector_id']!==$r['connector_id']
               || $prior['host_evidence_id']!==$r['host_evidence_id']
               || $prior['owner_binding']!==$r['owner_binding']
               || $prior['credential_fingerprint']!==$r['credential_fingerprint']
               || $prior['scope']!==self::READ_SCOPE) self::denied();
            $u=$db->prepare('UPDATE mirage_oauth_refresh_tokens SET consumed=1 WHERE refresh_hash=? AND consumed=0');
            $u->execute([$hash]); if($u->rowCount()!==1)self::denied();
            $access=self::secret(); $next=self::secret();
            $a=$db->prepare('INSERT INTO mirage_oauth_tokens
              (token_hash,client_id,connector_id,host_evidence_id,resource,scope,
               owner_binding,credential_fingerprint,issued_at,expires_at,revoked)
              VALUES(?,?,?,?,?,?,?,?,?,?,0)');
            $a->execute([hash('sha256',$access),$r['client_id'],$r['connector_id'],$r['host_evidence_id'],
              self::RESOURCE,self::READ_SCOPE,$r['owner_binding'],$r['credential_fingerprint'],$now,$now+self::ACCESS_TTL]);
            $n=$db->prepare('INSERT INTO mirage_oauth_refresh_tokens
              (refresh_hash,client_id,connector_id,host_evidence_id,resource,scope,
               owner_binding,credential_fingerprint,issued_at,expires_at,parent_access_hash)
              VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $n->execute([hash('sha256',$next),$r['client_id'],$r['connector_id'],$r['host_evidence_id'],
              self::RESOURCE,self::READ_SCOPE,$r['owner_binding'],$r['credential_fingerprint'],$now,
              $now+self::REFRESH_TTL,hash('sha256',$access)]);
            $db->exec('COMMIT');
            return ['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>self::ACCESS_TTL,
              'refresh_token'=>$next,'refresh_expires_in'=>self::REFRESH_TTL,'scope'=>self::READ_SCOPE];
        } catch(Throwable $e) { if($db->inTransaction())$db->exec('ROLLBACK'); throw $e; }
    }

    private static function sqlite(PDO $db):void { if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')self::denied(); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); }
    private static function secret():string { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }
    private static function secretFormat(string $v):bool { return (bool)preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$v); }
    private static function hex64(string $v):bool { return (bool)preg_match('/\A[a-f0-9]{64}\z/D',$v); }
    private static function denied():never { throw new RuntimeException('OAUTH_CONTINUITY_DENIED'); }
}
