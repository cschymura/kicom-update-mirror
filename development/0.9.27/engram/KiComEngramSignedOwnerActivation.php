<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramHostingPolicy.php';
require_once __DIR__.'/KiComEngramOAuthHttp.php';
require_once __DIR__.'/KiComEngramPrivateOwnerRegistry.php';
require_once __DIR__.'/KiComEngramActivationTransaction.php';

/**
 * DEV-64: explicitly signed, FIRST-PARTY owner activation of an ALREADY
 * reviewed KiCom host. This is NOT itself an HTTP route, nor a login,
 * a WebAuthn enrollment, or an unreviewed config upgrader.
 *
 * Original admin.php must supply ORIGINAL PHP admin session/session_id()/csrf,
 * original KiComPasskeyBridge, trusted __DIR__, and verified HTTPS.
 * All paths/policies/owner identities are derived from protected server files,
 * never browser/MCP/OAuth/Slack/GitHub.
 *
 * The review/signature process is independent from DEV-63 hosting acceptance.
 * The inactive database & OAuth schema must already exist (DEV-60).
 */
final class KiComEngramSignedOwnerActivation
{
    public const CONSENT='ENGRAM UND CHATGPT LESEZUGRIFF AKTIVIEREN';
    private const OWNER='mirage-owner';
    private const FLAGS=[
        'enabled','operator_approved','review_enabled',
        'oauth_enabled','mcp_connector_enabled'
    ];

    public static function begin(
        string $webRoot,array &$session,string $sessionId,string $csrf,
        string $postedCsrf,bool $https,KiComPasskeyBridge $passkeys,int $now
    ):array {
        self::admin($session,$sessionId,$csrf,$postedCsrf,$https);
        unset($session['mirage_activation_pending']);
        try {
            [$web,$path,$host,$hash,$activationFile,$oauthFile]=self::inspect($webRoot);
            if(($passkeys->ready()['ok']??null)!==true)self::deny();
            $pair=sodium_crypto_box_keypair();
            try{$public=KiComPasskeyBridge::b64uEncode(sodium_crypto_box_publickey($pair));}
            finally{sodium_memzero($pair);}
            $challenge=$passkeys->createAuthChallenge($public);
            if(($challenge['ok']??null)!==true || !is_string($challenge['challenge_id']??null))
                self::deny();
            $opts=$passkeys->assertionOptions($challenge['challenge_id']);
            if(($opts['ok']??null)!==true
                || ($opts['publicKey']['userVerification']??null)!=='required'
                || empty($opts['publicKey']['allowCredentials']))self::deny();
            $session['mirage_activation_pending']=[
                'challenge_id'=>$challenge['challenge_id'],
                'session_hash'=>hash('sha256',$sessionId),
                'csrf_hash'=>hash('sha256',$csrf),
                'config_hash'=>$hash,
                'owner_binding'=>$host['owner_binding'],
                'host_evidence_id'=>$host['host_evidence_id'],
                'expires_at'=>$now+120,
            ];
            return [
                'challenge_id'=>$challenge['challenge_id'],
                'publicKey'=>$opts['publicKey'],
                'confirmation'=>self::CONSENT,
                'hosting_mode'=>KiComEngramHostingPolicy::mode($host),
                'will_enable_oauth_and_read_only_mcp'=>true,
                'will_store_new_personal_memories'=>false
            ];
        }catch(Throwable){unset($session['mirage_activation_pending']);self::deny();}
    }

    public static function confirm(
        string $webRoot,array &$session,string $sessionId,string $csrf,
        string $postedCsrf,bool $https,string $confirmation,
        string $challengeId,array $assertion,KiComPasskeyBridge $passkeys,int $now
    ):array {
        self::admin($session,$sessionId,$csrf,$postedCsrf,$https);
        $pending=$session['mirage_activation_pending']??null;
        unset($session['mirage_activation_pending']); // burn before signature check
        if(!is_array($pending) || $confirmation!==self::CONSENT
            || !is_string($pending['challenge_id']??null)
            || !hash_equals($pending['challenge_id'],$challengeId)
            || !hash_equals((string)($pending['session_hash']??''),hash('sha256',$sessionId))
            || !hash_equals((string)($pending['csrf_hash']??''),hash('sha256',$csrf))
            || !is_string($pending['config_hash']??null)
            || !is_string($pending['owner_binding']??null)
            || !is_string($pending['host_evidence_id']??null)
            || (int)($pending['expires_at']??0)<=$now)self::deny();

        $db=null;$lock=null;$original=null;$dbCommitted=false;$configCommitted=false;
        try {
            $proof=$passkeys->verifyAssertion($challengeId,$assertion);
            if(($proof['ok']??null)!==true
                || !is_string($proof['credential_id']??null)
                || $proof['credential_id']==='')self::deny();
            $fp=hash('sha256',$proof['credential_id']);
            [$web,$path,$host,$hash,$activationFile,$oauthFile]=self::inspect($webRoot);
            if(!hash_equals($pending['config_hash'],$hash)
                || !hash_equals($pending['owner_binding'],$host['owner_binding'])
                || !hash_equals($pending['host_evidence_id'],$host['host_evidence_id']))
                self::deny();
            $owner=(new KiComEngramPrivateOwnerRegistry(
                $host['owner_registry'],$web
            ))($fp);
            if(!is_array($owner)
                || ($owner['enabled']??null)!==true
                || ($owner['credential_fingerprint']??null)!==$fp
                || ($owner['subject']??null)!==self::OWNER
                || ($owner['namespaces']??null)!==['project']
                || !in_array('engram.read',$owner['engram_rights']??[],true)
                || !hash_equals($host['owner_binding'],hash('sha256',self::OWNER."\0".$fp)))
                self::deny();

            // Acquire an independent host-wide activation lock before looking
            // at actual SQLite state. Never edit an unexpected/existing
            // activation, OAuth grant or any private memory content.
            $root=dirname($web).'/engram-private';
            $lockPath=$root.'/.mirage-owner-activate.lock';
            if(is_link($lockPath))self::deny();
            $oldMask=umask(0077);
            try{
                $lock=@fopen($lockPath,'c');
                if(!is_resource($lock)||!flock($lock,LOCK_EX))self::deny();
                @chmod($lockPath,0600);
                [$web,$path,$host,$hash,$activationFile,$oauthFile]=self::inspect($web);
                if(!hash_equals($pending['config_hash'],$hash))self::deny();
                $oauth=self::openPrivateDb($oauthFile);
                try {
                    if((int)$oauth->query('SELECT count(*) FROM mirage_oauth_codes')->fetchColumn()!==0
                        || (int)$oauth->query('SELECT count(*) FROM mirage_oauth_tokens')->fetchColumn()!==0)
                        self::deny();
                }finally{unset($oauth);}
                $db=self::openPrivateDb($activationFile);
                $rows=$db->query('SELECT state,owner_binding,host_evidence_id,approval_nonce
                    FROM activation_state WHERE singleton=1')->fetchAll(PDO::FETCH_ASSOC);
                if(count($rows)!==1
                    || ($rows[0]['state']??null)!=='inactive'
                    || ($rows[0]['owner_binding']??null)!==''
                    || ($rows[0]['host_evidence_id']??null)!==''
                    || ($rows[0]['approval_nonce']??null)!=='')self::deny();
                $date=gmdate('Y-m-d\TH:i:s\Z',$now);
                $hostEvidence=[
                    'eligible'=>true,
                    'status'=>'SHARED_HOST_RISK_EXPLICITLY_ACCEPTED_API_STILL_INACTIVE',
                    'evidence_id'=>$host['host_evidence_id'],
                    'checked_at_utc'=>$date,
                    'hosting_policy_mode'=>KiComEngramHostingPolicy::SHARED_MODE,
                    'hosting_policy_owner'=>self::OWNER,
                    'known_limitation'=>'shared-php-uid-not-verified',
                    'operator_accepts_shared_host_risk'=>true,
                ];
                $ownerEvidence=[
                    'schema'=>'mirage-owner-readiness/v1','verified'=>true,
                    'owner_binding'=>$host['owner_binding'],
                    'host_evidence_id'=>$host['host_evidence_id'],
                    'checked_at_utc'=>$date,'private_api_inactive'=>true,
                    'mcp_connector_connected'=>false,
                ];
                $nonce=bin2hex(random_bytes(32));
                $approval=[
                    'schema'=>'mirage-activation-approval/v1',
                    'approved'=>true,'purpose'=>'activate-private-engram-shared-host',
                    'owner_binding'=>$host['owner_binding'],
                    'host_evidence_id'=>$host['host_evidence_id'],
                    'approval_nonce'=>$nonce,'approved_at_utc'=>$date,
                ];

                // Construct AND validate the intended private reviewed policy
                // BEFORE changing the activation DB, but publish LAST.
                $new=$host;
                foreach(self::FLAGS as $flag)$new[$flag]=true;
                if(!KiComEngramHostingPolicy::permits($new)
                    || !KiComEngramOAuthHttp::available($new))self::deny();
                $toWrite=['schema'=>1]+$new;
                $json=json_encode($toWrite,
                    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
                if(strlen($json)>16384)self::deny();
                $original=@file_get_contents($path);
                if(!is_string($original)
                    || !hash_equals(hash('sha256',$original),$hash))self::deny();

                KiComEngramActivationTransaction::apply(
                    $db,$hostEvidence,$ownerEvidence,$host['owner_binding'],$approval,
                    (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'))
                );
                $dbCommitted=true;
                // Last operation exposes OAuth/MCP. Until this atomic rename,
                // the original policy remains disabled and all network gates
                // reject tokens and private memory reads.
                self::replaceConfig($root,$path,$hash,$original,$json);
                $configCommitted=true;
                return [
                    'ok'=>true,'code'=>'PRIVATE_ENGRAM_OAUTH_MCP_READ_ACTIVATED',
                    'hosting_mode'=>'shared_host_risk_explicitly_accepted_not_isolated',
                    'oauth_enabled'=>true,'mcp_enabled'=>true,'memory_read_enabled'=>true,
                    'memory_write_enabled'=>false,
                ];
            }finally{
                if(is_resource($lock)){@flock($lock,LOCK_UN);@fclose($lock);}
                umask($oldMask);
            }
        }catch(Throwable){
            if($dbCommitted && !$configCommitted && $db instanceof PDO){
                // No external memory access was possible because the public
                // config remained OFF; undo our uniquely scoped nonce. If
                // compensation cannot be verified, do NOT claim success.
                try {
                    $stmt=$db->prepare("UPDATE activation_state SET state='inactive',
                      owner_binding='',host_evidence_id='',approval_nonce=''
                      WHERE singleton=1 AND state='active' AND approval_nonce=?");
                    $stmt->execute([$nonce??'']);
                }catch(Throwable){}
            }
            self::deny();
        }
    }

    private static function inspect(string $webRoot):array
    {
        $web=realpath($webRoot);
        if(!is_string($web)||$web==='/'||is_link($webRoot)
            || !is_file($web.'/lib.php')||!is_file($web.'/admin.php'))self::deny();
        $root=dirname($web).'/engram-private';
        $path=$root.'/engram-host.json';
        foreach([$root,$root.'/data',$root.'/owners',$root.'/backups']as $dir)
            if(!self::dir($dir))self::deny();
        if(!self::file($path,16384))self::deny();
        $raw=@file_get_contents($path,false,null,0,16385);
        if(!is_string($raw)||strlen($raw)>16384)self::deny();
        $doc=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
        if(!is_array($doc)||array_is_list($doc)||($doc['schema']??null)!==1)self::deny();
        unset($doc['schema']);
        if(($doc['runtime_source']??null)!=='server-only-reviewed'
            || ($doc['web_root']??null)!==$web
            || ($doc['data_dir']??null)!==$root.'/data'
            || ($doc['owner_registry']??null)!==$root.'/owners/engram-owners.json'
            || ($doc['rp_id']??null)!=='kicom.rurtalbahn.info'
            || ($doc['expected_origin']??null)!=='https://kicom.rurtalbahn.info'
            || !KiComEngramHostingPolicy::permits($doc)
            || ($doc['host_isolation_verified']??null)!==false
            || ($doc['admin_subject']??null)!==self::OWNER
            || ($doc['private_memory_scope']??null)!=='dev-verified-owner'
            || ($doc['mcp_connector_id']??null)!=='mirage-chatgpt-oauth')
            self::deny();
        foreach(self::FLAGS as $flag)if(($doc[$flag]??null)!==false)self::deny();
        if(!is_array($doc['oauth_client']??null)
            || ($doc['oauth_client']['client_id']??null)!==KiComEngramOAuthHttp::STABLE_CHATGPT_CLIENT
            || ($doc['oauth_client']['redirect_uri']??null)!==KiComEngramOAuthHttp::STABLE_CHATGPT_CALLBACK
            || ($doc['oauth_client']['connector_id']??null)!==$doc['mcp_connector_id']
            || ($doc['oauth_client']['host_evidence_id']??null)!==$doc['host_evidence_id']
            || ($doc['oauth_issuer_response_supported']??null)!==true)
            self::deny();
        $data=$root.'/data';
        foreach(['engrams.sqlite','mirage-activation.sqlite','mirage-oauth.sqlite']as $f)
            if(!self::file($data.'/'.$f,10485760))self::deny();
        // The existing private memory database must be a real, readable
        // original Engram store, not a zero-byte placeholder or a path
        // accidentally created as part of the activation HTTP request.
        $memory=self::openPrivateDb($data.'/engrams.sqlite');
        try{$memory->query('SELECT id,revision FROM engram_revisions LIMIT 0')->fetchAll();}
        finally{unset($memory);}
        if(!self::file($root.'/owners/engram-owners.json',65536))self::deny();
        return [$web,$path,$doc,hash('sha256',$raw),
            $data.'/mirage-activation.sqlite',$data.'/mirage-oauth.sqlite'];
    }

    private static function replaceConfig(
        string $root,string $path,string $expectedHash,string $before,string $next
    ):void {
        $backup=$root.'/backups/engram-host-before-activation-'.
            gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $temp=$root.'/.mirage-activate-'.bin2hex(random_bytes(10)).'.json';
        $oldMask=umask(0077);
        $committed=false;
        try{
            $f=@fopen($backup,'x+b');
            if($f===false)self::deny();
            try{
                if(!@chmod($backup,0600)
                    || @fwrite($f,$before)!==strlen($before)
                    || !@fflush($f))self::deny();
            }finally{fclose($f);}
            $f=@fopen($temp,'x+b');
            if($f===false)self::deny();
            try{
                if(!@chmod($temp,0600)
                    || @fwrite($f,$next)!==strlen($next)
                    || !@fflush($f))self::deny();
            }finally{fclose($f);}
            if(!self::file($backup,16384)||!self::file($temp,16384)
                || !hash_equals((string)hash_file('sha256',$backup),$expectedHash)
                || !hash_equals((string)hash_file('sha256',$path),$expectedHash))
                self::deny();
            if(!@rename($temp,$path))self::deny();
            $committed=true;
        }finally{
            if(!$committed){
                @unlink($temp);
                // Keep the ORIGINAL backup even if activation failed; never
                // delete an operator-owned file during recovery.
            }
            umask($oldMask);
        }
    }
    private static function openPrivateDb(string $path):PDO
    {
        if(!self::file($path,10485760))self::deny();
        $db=new PDO('sqlite:'.$path,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>2
        ]);
        if($db->query('PRAGMA quick_check')->fetchColumn()!=='ok')self::deny();
        return $db;
    }
    private static function dir(string $p):bool
    {
        clearstatcache(true,$p);$s=@lstat($p);
        return is_array($s)&&($s['mode']&0170000)===0040000
            &&($s['mode']&0077)===0&&!is_link($p)&&is_dir($p);
    }
    private static function file(string $p,int $max):bool
    {
        clearstatcache(true,$p);$s=@lstat($p);
        return is_array($s)&&($s['mode']&0170000)===0100000
            &&($s['mode']&0077)===0&&($s['nlink']??0)===1
            &&($s['size']??0)>0&&($s['size']??PHP_INT_MAX)<=$max
            &&!is_link($p)&&is_file($p);
    }
    private static function admin(
        array $session,string $id,string $csrf,string $posted,bool $https
    ):void {
        if(!$https||($session['admin']??null)!==true||strlen($id)<24
            ||strlen($csrf)<24||!is_string($session['csrf']??null)
            ||!hash_equals($session['csrf'],$csrf)||!hash_equals($csrf,$posted))
            self::deny();
    }
    private static function deny():never
    {
        throw new RuntimeException('MIRAGE_ENGRAM_ACTIVATION_DENIED');
    }
}
