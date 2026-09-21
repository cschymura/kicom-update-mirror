<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramPrivateOwnerRegistry.php';
require_once __DIR__.'/KiComEngramHostingPolicy.php';
require_once __DIR__.'/KiComEngramOAuthHttp.php';

/**
 * DEV-63: FIRST-PARTY admin-only transition of the existing real private
 * setup-pending host JSON to an explicitly accepted shared-host policy.
 * It NEVER sets enabled, operator_approved, review_enabled, oauth_enabled,
 * mcp_connector_enabled or host_isolation_verified to true; no token/DB or
 * private memory is read/written. This is NOT a public HTTP endpoint.
 *
 * The original KiCom administrator MUST instantiate its original
 * KiComPasskeyBridge and supply original PHP session, session_id(), csrf()
 * and __DIR__. Only an actual signed fresh original owner assertion can
 * authorize a config transition. Configuration, owner, paths, client and
 * trust fields are NEVER accepted from HTTP request input.
 */
final class KiComEngramSharedHostAdminConfig
{
    private const CONSENT='GEMEINSAMES HOSTING FUER ENGRAM AKZEPTIERT';
    private const CLIENT='mirage-chatgpt-oauth';

    public static function begin(
        string $webRoot,array &$session,string $sessionId,string $csrf,
        string $postedCsrf,KiComPasskeyBridge $passkeys,int $now
    ):array {
        self::admin($session,$sessionId,$csrf,$postedCsrf);
        unset($session['mirage_host_policy_pending']);
        try {
            [$web,$config,$doc,$hash]=self::readPending($webRoot);
            if(($passkeys->ready()['ok']??null)!==true)self::deny();
            $pair=sodium_crypto_box_keypair();
            try {
                $pub=KiComPasskeyBridge::b64uEncode(
                    sodium_crypto_box_publickey($pair)
                );
            } finally {sodium_memzero($pair);}
            $created=$passkeys->createAuthChallenge($pub);
            if(($created['ok']??null)!==true
                || !is_string($created['challenge_id']??null))self::deny();
            $options=$passkeys->assertionOptions($created['challenge_id']);
            if(($options['ok']??null)!==true
                || ($options['publicKey']['userVerification']??null)!=='required'
                || empty($options['publicKey']['allowCredentials']))self::deny();
            $session['mirage_host_policy_pending']=[
                'challenge_id'=>$created['challenge_id'],
                'session_hash'=>hash('sha256',$sessionId),
                'csrf_hash'=>hash('sha256',$csrf),
                'original_config_hash'=>$hash,
                'expires_at'=>$now+120
            ];
            return [
                'challenge_id'=>$created['challenge_id'],
                'publicKey'=>$options['publicKey'],
                'known_limitation'=>'shared-php-uid-not-verified',
                'will_activate_memory'=>false,
                'will_create_oauth_tokens'=>false,
                'confirmation'=>self::CONSENT
            ];
        }catch(Throwable $e){
            unset($session['mirage_host_policy_pending']);self::deny();
        }
    }

    public static function confirm(
        string $webRoot,array &$session,string $sessionId,string $csrf,
        string $postedCsrf,string $confirmation,string $challengeId,
        array $assertion,KiComPasskeyBridge $passkeys,int $now
    ):array {
        self::admin($session,$sessionId,$csrf,$postedCsrf);
        $pending=$session['mirage_host_policy_pending']??null;
        unset($session['mirage_host_policy_pending']);
        if($confirmation!==self::CONSENT || !is_array($pending)
            || !is_string($pending['challenge_id']??null)
            || !hash_equals($pending['challenge_id'],$challengeId)
            || !hash_equals((string)($pending['session_hash']??''),hash('sha256',$sessionId))
            || !hash_equals((string)($pending['csrf_hash']??''),hash('sha256',$csrf))
            || !is_string($pending['original_config_hash']??null)
            || (int)($pending['expires_at']??0)<=$now)self::deny();

        try {
            $proof=$passkeys->verifyAssertion($challengeId,$assertion);
            if(($proof['ok']??null)!==true
                || !is_string($proof['credential_id']??null)
                || $proof['credential_id']==='')self::deny();
            $fp=hash('sha256',$proof['credential_id']);
            [$web,$path,$before,$hash]=self::readPending($webRoot);
            if(!hash_equals($pending['original_config_hash'],$hash))self::deny();
            $root=dirname($web).'/engram-private';
            $owners=new KiComEngramPrivateOwnerRegistry(
                $root.'/owners/engram-owners.json',$web
            );
            $owner=$owners($fp);
            if(!is_array($owner)
                || ($owner['enabled']??null)!==true
                || ($owner['subject']??null)!=='mirage-owner'
                || ($owner['credential_fingerprint']??null)!==$fp
                || ($owner['namespaces']??null)!==['project']
                || !in_array('engram.read',$owner['engram_rights']??[],true))
                self::deny();

            $binding=hash('sha256',"mirage-owner\0".$fp);
            // This is a documented operator-selected policy SNAPSHOT id,
            // NOT proof that PHP-UID/vhost isolation has been established.
            $hostId=hash('sha256',"mirage-shared-host-acceptance/v1\0".
                $web."\0".$hash."\0".$binding);
            $new=$before;
            $new['runtime_source']='server-only-reviewed';
            $new['private_memory_scope']='dev-verified-owner';
            $new['admin_subject']='mirage-owner';
            $new['reviewed_web_roots']=[$web];
            $new['operator_accepts_shared_host_risk']=true;
            $new['hosting_policy_mode']=KiComEngramHostingPolicy::SHARED_MODE;
            $new['hosting_policy_source']='protected-operator-host-config';
            $new['hosting_policy_owner']='mirage-owner';
            $new['hosting_policy_known_limitation']='shared-php-uid-not-verified';
            $new['hosting_policy_acknowledged_at_utc']=gmdate('Y-m-d\TH:i:s\Z',$now);
            $new['owner_binding']=$binding;
            $new['host_evidence_id']=$hostId;
            $new['mcp_connector_id']=self::CLIENT;
            $new['oauth_client']=[
                'client_id'=>KiComEngramOAuthHttp::STABLE_CHATGPT_CLIENT,
                'redirect_uri'=>KiComEngramOAuthHttp::STABLE_CHATGPT_CALLBACK,
                'connector_id'=>self::CLIENT,
                'host_evidence_id'=>$hostId
            ];
            $new['oauth_issuer_response_supported']=true;

            // Retain and test ALL original disabled flags. The new private
            // configuration cannot issue a token or expose a memory by itself.
            foreach(['enabled','operator_approved','review_enabled',
                     'host_isolation_verified'] as $flag)
                if(($new[$flag]??null)!==false)self::deny();
            $new['oauth_enabled']=false;
            $new['mcp_connector_enabled']=false;
            if(!KiComEngramHostingPolicy::permits($new)
                || KiComEngramOAuthHttp::available($new))self::deny();

            $encoded=json_encode($new,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if(strlen($encoded)>16384)self::deny();
            self::replaceOnce($web,$path,$hash,$encoded);
            return [
                'ok'=>true,'code'=>'SHARED_HOST_POLICY_PREPARED_INACTIVE',
                'hosting_mode'=>'shared_host_risk_explicitly_accepted_not_isolated',
                'memory_enabled'=>false,'oauth_enabled'=>false,
                'mcp_enabled'=>false,'host_isolation_verified'=>false
            ];
        }catch(Throwable){self::deny();}
    }

    private static function admin(array $session,string $id,string $csrf,string $posted):void
    {
        if(($session['admin']??null)!==true || strlen($id)<24
            || strlen($csrf)<24 || !is_string($session['csrf']??null)
            || !hash_equals($session['csrf'],$csrf)
            || !hash_equals($csrf,$posted))self::deny();
    }

    private static function readPending(string $webRoot):array
    {
        $web=realpath($webRoot);
        if(!is_string($web)||$web==='/'||is_link($webRoot)
            || !is_file($web.'/lib.php')||!is_file($web.'/admin.php'))
            self::deny();
        $root=dirname($web).'/engram-private';
        $path=$root.'/engram-host.json';
        foreach([$root,$root.'/owners',$root.'/data',$root.'/backups']as $dir){
            $s=@lstat($dir);
            if(!is_array($s)||($s['mode']&0170000)!==0040000
                || ($s['mode']&0077)!==0||is_link($dir))self::deny();
        }
        $s=@lstat($path);
        if(!is_array($s)||($s['mode']&0170000)!==0100000
            || ($s['mode']&0077)!==0||($s['nlink']??0)!==1
            || ($s['size']??0)<1||($s['size']??0)>16384
            || is_link($path))self::deny();
        $raw=@file_get_contents($path,false,null,0,16385);
        if(!is_string($raw)||strlen($raw)!==$s['size'])self::deny();
        $doc=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($doc)||array_is_list($doc)
            || ($doc['schema']??null)!==1
            || ($doc['runtime_source']??null)!=='setup-pending'
            || ($doc['private_memory_scope']??null)!=='setup-pending'
            || ($doc['web_root']??null)!==$web
            || ($doc['data_dir']??null)!==$root.'/data'
            || ($doc['backups_dir']??null)!==$root.'/backups'
            || ($doc['owner_registry']??null)!==$root.'/owners/engram-owners.json'
            || ($doc['rp_id']??null)!=='kicom.rurtalbahn.info'
            || ($doc['expected_origin']??null)!=='https://kicom.rurtalbahn.info')
            self::deny();
        foreach(['enabled','operator_approved','review_enabled',
                 'host_isolation_verified']as $flag)
            if(($doc[$flag]??null)!==false)self::deny();
        if(($doc['oauth_enabled']??false)!==false
            || ($doc['mcp_connector_enabled']??false)!==false)self::deny();
        return [$web,$path,$doc,hash('sha256',$raw)];
    }

    private static function replaceOnce(
        string $web,string $path,string $expectedHash,string $encoded
    ):void {
        $root=dirname($web).'/engram-private';
        $lock=$root.'/.mirage-host-policy.lock';
        if(is_link($lock))self::deny();
        $oldMask=umask(0077);
        $handle=null;$temporary=null;
        try{
            $handle=@fopen($lock,'c');
            if($handle===false||!flock($handle,LOCK_EX))self::deny();
            @chmod($lock,0600);
            [$actualWeb,$actualPath,$doc,$actualHash]=self::readPending($web);
            if($actualPath!==$path || !hash_equals($expectedHash,$actualHash))self::deny();
            $temporary=$root.'/.mirage-host-policy-'.bin2hex(random_bytes(12)).'.json';
            $f=@fopen($temporary,'x+b');
            if($f===false)self::deny();
            try{
                if(!@chmod($temporary,0600)
                    || @fwrite($f,$encoded)!==strlen($encoded)
                    || !@fflush($f))self::deny();
            }finally{fclose($f);}
            // No overwrite of an already changed config; all writes remain
            // confined to the original protected setup file.
            $old=@file_get_contents($path);
            if(!is_string($old)||!hash_equals(hash('sha256',$old),$expectedHash))
                self::deny();
            if(!@rename($temporary,$path))self::deny();
            $temporary=null;
        }finally{
            if($temporary!==null)@unlink($temporary);
            if(is_resource($handle)){@flock($handle,LOCK_UN);@fclose($handle);}
            umask($oldMask);
        }
    }
    private static function deny():never {
        throw new RuntimeException('MIRAGE_HOST_POLICY_DENIED');
    }
}
