<?php
declare(strict_types=1);

/**
 * DEV-51: a PRIVATE OAuth 2.1 authorization-code + PKCE S256 transaction core.
 * NOT an HTTP route, OAuth issuer, credential verifier or live activation.
 * All client/client-callback policy comes from reviewed server configuration.
 * An authenticated first-party KiCom admin with a fresh verified Passkey must
 * call approve() after showing and obtaining explicit per-client read consent.
 *
 * A connector bearer token here is an OAuth access token issued for one
 * resource and one already bound owner, NOT the provisional DEV-47 static
 * token JSON format. The MCP gate must resolve the token against THIS store
 * before constructing its server-side identity.
 */
final class KiComEngramOAuthTransactions
{
    private const RESOURCE = 'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
    private const SCOPE = 'engram.read';

    /** Explicit operator-controlled schema installation, NEVER from HTTP. */
    public static function install(PDO $db): void
    {
        self::sqlite($db);
        $db->exec('CREATE TABLE IF NOT EXISTS mirage_oauth_codes (
            code_hash TEXT PRIMARY KEY, request_hash TEXT NOT NULL UNIQUE,
            client_id TEXT NOT NULL, redirect_uri TEXT NOT NULL,
            resource TEXT NOT NULL, state TEXT NOT NULL,
            pkce_challenge TEXT NOT NULL, owner_binding TEXT NOT NULL,
            admin_session_hash TEXT NOT NULL, issued_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL, consent_at INTEGER NOT NULL DEFAULT 0,
            approved_fingerprint TEXT NOT NULL DEFAULT "", consumed INTEGER NOT NULL DEFAULT 0)');
        $db->exec('CREATE TABLE IF NOT EXISTS mirage_oauth_tokens (
            token_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL,
            resource TEXT NOT NULL, scope TEXT NOT NULL,
            owner_binding TEXT NOT NULL, credential_fingerprint TEXT NOT NULL,
            issued_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
            revoked INTEGER NOT NULL DEFAULT 0)');
    }

    /**
     * Starts a pending request scoped to the existing operator-pinned client,
     * exact redirect and canonical MCP resource. Caller MUST have loaded the
     * original KiCom admin session; browser query cannot provide that session.
     */
    public static function begin(
        PDO $db,array $p,array $trustedClient,string $adminSessionId,int $now
    ): array {
        self::sqlite($db);
        self::client($trustedClient);
        self::keys($p,[
          'client_id','redirect_uri','response_type','scope','resource',
          'state','code_challenge','code_challenge_method'
        ]);
        if ($p['client_id'] !== $trustedClient['client_id']
            || $p['redirect_uri'] !== $trustedClient['redirect_uri']
            || $p['response_type'] !== 'code'
            || $p['scope'] !== self::SCOPE
            || $p['resource'] !== self::RESOURCE
            || $p['code_challenge_method'] !== 'S256'
            || !self::challenge($p['code_challenge'])
            || !is_string($p['state']) || !preg_match('/\A[A-Za-z0-9._~-]{16,200}\z/D',$p['state'])
            || strlen($adminSessionId) < 24) self::denied();

        $requestId = self::secret();
        $code = self::secret();
        $q=$db->prepare('INSERT INTO mirage_oauth_codes
          (code_hash,request_hash,client_id,redirect_uri,resource,state,
           pkce_challenge,owner_binding,admin_session_hash,issued_at,expires_at)
          VALUES(?,?,?,?,?,?,?,"",?,?,?)');
        $q->execute([
          hash('sha256',$code),hash('sha256',$requestId),
          $p['client_id'],$p['redirect_uri'],self::RESOURCE,$p['state'],
          $p['code_challenge'],hash('sha256',$adminSessionId),
          $now,$now+300
        ]);
        // The authorization CODE itself is never revealed until the user
        // explicitly consents with a fresh Passkey assertion.
        return ['request_id'=>$requestId,'expires_at'=>$now+300];
    }

    /**
     * This is an INTERNAL integration boundary, NOT a client-callable bool.
     * $verifiedFingerprint MUST be the result of the ORIGINAL KiCom's fresh
     * signed WebAuthn verification for the same original admin PHP session.
     * $lookupOwner MUST be KiComEngramPrivateOwnerRegistry, loaded anew.
     * The user consent UI must bind this exact OAuth request to that proof.
     */
    public static function approve(
        PDO $db,string $requestId,string $adminSessionId,
        string $verifiedFingerprint,callable $lookupOwner,
        bool $explicitReadConsent,int $now
    ): array {
        self::sqlite($db);
        if (!$explicitReadConsent || !self::secretFormat($requestId)
            || !self::hex64($verifiedFingerprint) || strlen($adminSessionId)<24) self::denied();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $q=$db->prepare('SELECT * FROM mirage_oauth_codes WHERE request_hash=?');
            $q->execute([hash('sha256',$requestId)]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['consent_at']!=='0' || $row['consumed']!=='0'
                || (int)$row['expires_at']<=$now || (int)$row['issued_at']>$now
                || !hash_equals($row['admin_session_hash'],hash('sha256',$adminSessionId))) self::denied();
            // Refuse a stale or revoked owner at EACH approval. No owner or
            // rights are ever taken from the OAuth client or browser request.
            $owner=$lookupOwner($verifiedFingerprint);
            if (!is_array($owner) || ($owner['enabled']??null)!==true
                || ($owner['subject']??null)!=='mirage-owner'
                || ($owner['credential_fingerprint']??null)!==$verifiedFingerprint
                || ($owner['namespaces']??null)!==['project']
                || !is_array($owner['engram_rights']??null)
                || !in_array('engram.read',$owner['engram_rights'],true)) self::denied();

            $binding=hash('sha256',"mirage-owner\0".$verifiedFingerprint);
            $q=$db->prepare('UPDATE mirage_oauth_codes SET
                  consent_at=?,owner_binding=?,approved_fingerprint=?
                  WHERE request_hash=? AND consent_at=0 AND consumed=0');
            $q->execute([$now,$binding,$verifiedFingerprint,hash('sha256',$requestId)]);
            if($q->rowCount()!==1) self::denied();
            $db->exec('COMMIT');
            // DO NOT send an OAuth code to the connector from this return:
            // only the trusted first-party redirect handler may do so.
            return ['approved'=>true,'redirect_uri'=>$row['redirect_uri'],
              'state'=>$row['state'],'request_hash'=>hash('sha256',$requestId)];
        } catch(Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * The original code is held privately by the authorization controller,
     * never in a browser field. This method creates the code only AFTER
     * approval so it can be included once in a pinned redirect.
     */
    public static function issueApprovedCode(PDO $db,string $requestId,int $now):array
    {
        self::sqlite($db);
        if(!self::secretFormat($requestId))self::denied();
        $db->exec('BEGIN IMMEDIATE');
        try{
            $q=$db->prepare('SELECT * FROM mirage_oauth_codes WHERE request_hash=?');
            $q->execute([hash('sha256',$requestId)]);$r=$q->fetch(PDO::FETCH_ASSOC);
            if(!$r || (int)$r['consent_at']<=0 || $r['consumed']!=='0'
                || (int)$r['expires_at']<=$now)self::denied();
            $code=self::secret();
            $q=$db->prepare('UPDATE mirage_oauth_codes SET code_hash=?,
              consumed=2 WHERE request_hash=? AND consumed=0');
            $q->execute([hash('sha256',$code),hash('sha256',$requestId)]);
            if($q->rowCount()!==1)self::denied();
            $db->exec('COMMIT');
            return ['code'=>$code,'state'=>$r['state'],'redirect_uri'=>$r['redirect_uri']];
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }

    /** Single-use authorization-code + exact PKCE/resource/client binding. */
    public static function exchange(PDO $db,array $p,array $client,int $now):array
    {
        self::sqlite($db);self::client($client);
        self::keys($p,['grant_type','code','code_verifier','redirect_uri','client_id','resource']);
        if($p['grant_type']!=='authorization_code' || !self::secretFormat($p['code'])
            || $p['redirect_uri']!==$client['redirect_uri']
            || $p['client_id']!==$client['client_id']
            || $p['resource']!==self::RESOURCE
            || !is_string($p['code_verifier'])
            || !preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D',$p['code_verifier']))self::denied();
        $db->exec('BEGIN IMMEDIATE');
        try{
            $q=$db->prepare('SELECT * FROM mirage_oauth_codes WHERE code_hash=?');
            $q->execute([hash('sha256',$p['code'])]);$r=$q->fetch(PDO::FETCH_ASSOC);
            $c=rtrim(strtr(base64_encode(hash('sha256',$p['code_verifier'],true)),'+/','-_'),'=');
            if(!$r || $r['consumed']!=='2'||(int)$r['expires_at']<=$now
                || $r['client_id']!==$client['client_id']
                || $r['redirect_uri']!==$client['redirect_uri']
                || $r['resource']!==self::RESOURCE
                || !hash_equals($r['pkce_challenge'],$c)
                || !self::hex64($r['owner_binding'])
                || !self::hex64($r['approved_fingerprint']))self::denied();
            $q=$db->prepare('UPDATE mirage_oauth_codes SET consumed=1
                WHERE code_hash=? AND consumed=2');$q->execute([hash('sha256',$p['code'])]);
            if($q->rowCount()!==1)self::denied();
            $token=self::secret();
            $q=$db->prepare('INSERT INTO mirage_oauth_tokens
                (token_hash,client_id,resource,scope,owner_binding,
                credential_fingerprint,issued_at,expires_at)
                VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([hash('sha256',$token),$r['client_id'],self::RESOURCE,
                self::SCOPE,$r['owner_binding'],$r['approved_fingerprint'],$now,$now+3600]);
            $db->exec('COMMIT');
            return ['access_token'=>$token,'token_type'=>'Bearer',
                'expires_in'=>3600,'scope'=>self::SCOPE];
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }

    /** Server-side identity resolution; caller rechecks owner revocation and active host. */
    public static function verify(PDO $db,string $token,int $now):?array
    {
        self::sqlite($db);
        if(!self::secretFormat($token))return null;
        try{
            $q=$db->prepare('SELECT * FROM mirage_oauth_tokens WHERE token_hash=?');
            $q->execute([hash('sha256',$token)]);$row=$q->fetch(PDO::FETCH_ASSOC);
        }catch(Throwable){return null;}
        if(!$row || (int)$row['revoked']!==0
            || (int)$row['expires_at']<=$now || (int)$row['issued_at']>$now
            || $row['resource']!==self::RESOURCE || $row['scope']!==self::SCOPE
            || !self::hex64($row['credential_fingerprint'])
            || !self::hex64($row['owner_binding']))return null;
        return ['authenticated'=>true,'connector_id'=>$row['client_id'],
            'credential_fingerprint'=>$row['credential_fingerprint'],
            'owner_binding'=>$row['owner_binding']];
    }

    public static function revoke(PDO $db,string $token):void
    {
        self::sqlite($db);
        if(!self::secretFormat($token))self::denied();
        $q=$db->prepare('UPDATE mirage_oauth_tokens SET revoked=1 WHERE token_hash=?');
        $q->execute([hash('sha256',$token)]);
    }

    private static function client(array $client):void
    {
        self::keys($client,['client_id','redirect_uri']);
        if(!is_string($client['client_id'])
            || !preg_match('#\Ahttps://chatgpt\.com/oauth/(?:[a-zA-Z0-9_-]+/)?client\.json\z#D',$client['client_id'])
            || !is_string($client['redirect_uri'])
            || !preg_match('#\Ahttps://chatgpt\.com/[^?#]{1,160}\z#D',$client['redirect_uri']))self::denied();
        // These values must be pinned by operator after confirming the exact
        // ChatGPT plugin connection; do not accept arbitrary client documents.
    }
    private static function challenge(mixed $v):bool
    {
        return is_string($v) && preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$v)===1;
    }
    private static function secret():string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    }
    private static function secretFormat(mixed $v):bool
    {
        return is_string($v)&&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$v)===1;
    }
    private static function hex64(mixed $v):bool
    {
        return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;
    }
    private static function keys(array $p,array $required):void
    {
        $have=array_keys($p);sort($have,SORT_STRING);sort($required,SORT_STRING);
        if($have!==$required)self::denied();
    }
    private static function sqlite(PDO $db):void
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')self::denied();
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }
    private static function denied():never
    {
        throw new RuntimeException('MIRAGE_OAUTH_REQUEST_DENIED');
    }
}
