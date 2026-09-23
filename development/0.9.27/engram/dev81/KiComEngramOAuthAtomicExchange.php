<?php
declare(strict_types=1);

/**
 * DEV-81, review candidate: atomic implementation of the original strictly
 * pinned authorization-code exchange with issuance of ONE initial read-only
 * rotating refresh token. NOT called by an anonymous request to install tables.
 *
 * This duplicates the original 0.9.37 exchange transaction for integration
 * testing only; consolidate it with the original implementation before release
 * rather than keeping two independently maintained PKCE validators.
 */
final class KiComEngramOAuthAtomicExchange
{
    private const RESOURCE='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
    private const SCOPE='engram.read';
    private const ACCESS_TTL=3600;
    private const REFRESH_TTL=604800;

    /** Explicit owner-controlled private schema preparation; never from HTTP. */
    public static function prepareSchema(PDO $db):void
    {
        self::sqlite($db);
        KiComEngramOAuthTransactions::install($db);
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

    /** Existing 0.9.37 request conditions; one SQLite transaction for both tokens. */
    public static function redeem(PDO $db,array $p,array $trustedClient,int $now):array
    {
        self::sqlite($db);
        self::exactKeys($trustedClient,['client_id','connector_id','host_evidence_id','redirect_uri']);
        self::exactKeys($p,['grant_type','code','code_verifier','redirect_uri','client_id','resource']);
        if($p['grant_type']!=='authorization_code'
            || !self::b64secret($p['code'])
            || $p['redirect_uri']!==$trustedClient['redirect_uri']
            || $p['client_id']!==$trustedClient['client_id']
            || $p['resource']!==self::RESOURCE
            || !is_string($p['code_verifier'])
            || !preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D',$p['code_verifier'])
            || !is_string($trustedClient['client_id'])
            || !preg_match('#\Ahttps://chatgpt\.com/oauth/(?:[a-zA-Z0-9_-]+/)?client\.json\z#D',$trustedClient['client_id'])
            || !is_string($trustedClient['redirect_uri'])
            || !preg_match('%\Ahttps://chatgpt\.com/[^?#]{1,160}\z%D',$trustedClient['redirect_uri'])
            || !is_string($trustedClient['connector_id'])
            || !preg_match('/\A[a-z0-9][a-z0-9._:-]{2,63}\z/D',$trustedClient['connector_id'])
            || !self::hex64($trustedClient['host_evidence_id'])
        )self::deny();

        // Existing schema MUST have been prepared by the authenticated operator.
        $db->exec('BEGIN IMMEDIATE');
        try {
            $q=$db->prepare('SELECT * FROM mirage_oauth_codes WHERE code_hash=?');
            $q->execute([hash('sha256',$p['code'])]);
            $r=$q->fetch(PDO::FETCH_ASSOC);
            $challenge=rtrim(strtr(base64_encode(hash('sha256',$p['code_verifier'],true)),'+/','-_'),'=');
            if(!$r || (int)$r['consumed']!==2 || (int)$r['expires_at']<=$now
                || $r['client_id']!==$trustedClient['client_id']
                || $r['redirect_uri']!==$trustedClient['redirect_uri']
                || $r['connector_id']!==$trustedClient['connector_id']
                || $r['host_evidence_id']!==$trustedClient['host_evidence_id']
                || $r['resource']!==self::RESOURCE
                || !hash_equals($r['pkce_challenge'],$challenge)
                || !self::hex64($r['owner_binding'])
                || !self::hex64($r['approved_fingerprint'])
            )self::deny();
            $q=$db->prepare('UPDATE mirage_oauth_codes SET consumed=1 WHERE code_hash=? AND consumed=2');
            $q->execute([hash('sha256',$p['code'])]);
            if($q->rowCount()!==1)self::deny();

            $access=self::secret();$refresh=self::secret();
            $accessHash=hash('sha256',$access);
            $q=$db->prepare('INSERT INTO mirage_oauth_tokens
                (token_hash,client_id,connector_id,host_evidence_id,resource,scope,
                 owner_binding,credential_fingerprint,issued_at,expires_at)
                VALUES(?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$accessHash,$r['client_id'],$r['connector_id'],
                $r['host_evidence_id'],self::RESOURCE,self::SCOPE,$r['owner_binding'],
                $r['approved_fingerprint'],$now,$now+self::ACCESS_TTL]);
            $q=$db->prepare('INSERT INTO mirage_oauth_refresh_tokens
                (refresh_hash,client_id,connector_id,host_evidence_id,resource,scope,
                 owner_binding,credential_fingerprint,issued_at,expires_at,parent_access_hash)
                VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([hash('sha256',$refresh),$r['client_id'],$r['connector_id'],
                $r['host_evidence_id'],self::RESOURCE,self::SCOPE,$r['owner_binding'],
                $r['approved_fingerprint'],$now,$now+self::REFRESH_TTL,$accessHash]);
            $db->exec('COMMIT');
            return ['access_token'=>$access,'token_type'=>'Bearer',
                'expires_in'=>self::ACCESS_TTL,'refresh_token'=>$refresh,
                'refresh_expires_in'=>self::REFRESH_TTL,'scope'=>self::SCOPE];
        }catch(Throwable $e){
            if($db->inTransaction())$db->exec('ROLLBACK');
            throw $e;
        }
    }
    private static function exactKeys(array $p,array $names):void
    {
        $have=array_keys($p);sort($have,SORT_STRING);sort($names,SORT_STRING);
        if($have!==$names)self::deny();
    }
    private static function sqlite(PDO $db):void
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')self::deny();
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }
    private static function b64secret(mixed $s):bool
    {
        return is_string($s)&&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$s)===1;
    }
    private static function hex64(mixed $s):bool
    {
        return is_string($s)&&preg_match('/\A[a-f0-9]{64}\z/D',$s)===1;
    }
    private static function secret():string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    }
    private static function deny():never
    {
        throw new RuntimeException('OAUTH_CODE_EXCHANGE_DENIED');
    }
}
