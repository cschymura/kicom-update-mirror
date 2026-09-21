<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramOAuthTransactions.php';
require_once __DIR__.'/KiComEngramActivationTransaction.php';

/**
 * DEV-58: provision ONLY missing, INACTIVE private OAuth/activation schemas
 * from the ORIGINAL KiCom admin session and explicitly approved one-time
 * action. Not a public endpoint, token issuer, host-isolation attestation or
 * Engram activation. No existing database/config/owner record is replaced.
 * Caller must pass ORIGINAL $_SESSION, session_id(), csrf(), and __DIR__;
 * none may originate from request JSON.
 */
final class KiComEngramInactiveDbProvisioner
{
    private const FILES=[
        'mirage-activation.sqlite'=>'activation',
        'mirage-oauth.sqlite'=>'oauth',
    ];

    /**
     * The original KiCom runtime loader rejects setup-pending configurations,
     * correctly for public APIs. This separate admin-only loader accepts ONLY
     * a real, existing, private and INACTIVE setup-pending host configuration.
     * It does not promote setup-pending to server-only-reviewed.
     */
    public static function loadInactiveRuntime(string $trustedWebRoot):array
    {
        $web=realpath($trustedWebRoot);
        if(!is_string($web)||$web==='/'||is_link($trustedWebRoot))
            self::deny('TRUSTED_WEB_ROOT_UNAVAILABLE');
        $private=dirname($web).'/engram-private';
        $config=$private.'/engram-host.json';
        if(!self::privateDir($private)||realpath($private)!==$private
            || !self::privateFile($config,16384))
            self::deny('INACTIVE_PRIVATE_SCAFFOLD_REQUIRED');
        $raw=@file_get_contents($config,false,null,0,16385);
        if(!is_string($raw)||strlen($raw)>16384)
            self::deny('INACTIVE_PRIVATE_SCAFFOLD_REQUIRED');
        try{$doc=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
        catch(Throwable){self::deny('INACTIVE_PRIVATE_SCAFFOLD_REQUIRED');}
        if(!is_array($doc)||array_is_list($doc)
            || ($doc['schema']??null)!==1
            || ($doc['web_root']??null)!==$web
            || ($doc['data_dir']??null)!==$private.'/data'
            || ($doc['owner_registry']??null)!==$private.'/owners/engram-owners.json'
            || !in_array(($doc['runtime_source']??null),
                ['setup-pending','server-only-reviewed'],true)
            || ($doc['enabled']??null)!==false)
            self::deny('INACTIVE_PRIVATE_SCAFFOLD_REQUIRED');
        foreach(['operator_approved','oauth_enabled','mcp_connector_enabled',
                 'host_isolation_verified'] as $flag)
            if(($doc[$flag]??null)===true)
                self::deny('INACTIVE_PRIVATE_SCAFFOLD_REQUIRED');
        unset($doc['schema']);
        return $doc;
    }

    public static function prepare(
        string $trustedWebRoot,array $originalSession,string $sessionId,
        string $expectedCsrf,string $submittedCsrf,
        string $confirmation,array $serverRuntime,bool $https
    ):array {
        if (!$https || ($originalSession['admin']??null)!==true
            || !is_string($originalSession['csrf']??null)
            || !hash_equals($originalSession['csrf'],$expectedCsrf)
            || !hash_equals($expectedCsrf,$submittedCsrf)
            || strlen($sessionId)<24
            || !hash_equals('INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',$confirmation))
            self::deny('ADMIN_APPROVAL_REQUIRED');

        // Reject a caller-supplied 'inactive' claim if the actual private
        // host configuration is active, missing, or has changed.
        $actual=self::loadInactiveRuntime($trustedWebRoot);
        if ($serverRuntime!==$actual)
            self::deny('INACTIVE_PRIVATE_SCAFFOLD_REQUIRED');
        $web=realpath($trustedWebRoot);
        if (!is_string($web) || $web==='/' || is_link($trustedWebRoot))
            self::deny('TRUSTED_WEB_ROOT_UNAVAILABLE');
        $private=dirname($web).'/engram-private';
        $data=$private.'/data';
        $backups=$private.'/backups';
        $owners=$private.'/owners';
        $registry=$owners.'/engram-owners.json';
        $config=$private.'/engram-host.json';
        if (($serverRuntime['web_root']??null)!==$web
            || ($serverRuntime['data_dir']??null)!==$data
            || ($serverRuntime['owner_registry']??null)!==$registry
            || ($serverRuntime['enabled']??null)!==false
            || ($serverRuntime['operator_approved']??null)===true
            || ($serverRuntime['oauth_enabled']??null)===true
            || ($serverRuntime['mcp_connector_enabled']??null)===true
            || ($serverRuntime['host_isolation_verified']??null)===true
            || !self::privateDir($private)
            || realpath($private)!==$private
            || !self::privateDir($data)
            || !self::privateDir($backups)
            || !self::privateDir($owners)
            || !self::privateFile($registry,65536)
            || !self::privateFile($config,16384))
            self::deny('INACTIVE_PRIVATE_SCAFFOLD_REQUIRED');
        if (!extension_loaded('pdo_sqlite')
            || !in_array('sqlite',PDO::getAvailableDrivers(),true))
            self::deny('SQLITE_UNAVAILABLE');

        $oldMask=umask(0077);
        $lock=null;
        $installed=[];
        $temporaries=[];
        try {
            $lockPath=$data.'/.mirage-provision.lock';
            if (is_link($lockPath)
                || (file_exists($lockPath) && !self::privateLock($lockPath)))
                self::deny('PRIVATE_LOCK_INVALID');
            $lock=@fopen($lockPath,'c');
            if ($lock===false || !@flock($lock,LOCK_EX))self::deny('PRIVATE_LOCK_UNAVAILABLE');
            @chmod($lockPath,0600);
            $status=[];
            foreach (self::FILES as $filename=>$kind) {
                $path=$data.'/'.$filename;
                if (file_exists($path) || is_link($path)) {
                    if (!self::privateFile($path,10485760))
                        self::deny('EXISTING_DATABASE_UNSAFE');
                    $db=new PDO('sqlite:'.$path,null,null,[
                        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT=>2
                    ]);
                    $state=self::inspectSchema($db,$kind);
                    unset($db);
                    if ($state!=='inactive')self::deny('EXISTING_DATABASE_REQUIRES_REVIEW');
                    $status[$kind]='existing_inactive';
                    continue;
                }
                // Construct in the same restricted private directory. Rename
                // into final path ONLY after the schema is fully initialized.
                $tmp=$data.'/.mirage-staging-'.bin2hex(random_bytes(12)).'.sqlite';
                $temporaries[]=$tmp;
                $db=new PDO('sqlite:'.$tmp,null,null,[
                    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT=>2
                ]);
                @chmod($tmp,0600);
                if ($kind==='activation') {
                    if (KiComEngramActivationTransaction::state($db)!=='inactive')
                        self::deny('INITIAL_ACTIVATION_STATE_INVALID');
                } else KiComEngramOAuthTransactions::install($db);
                if (self::inspectSchema($db,$kind)!=='inactive')
                    self::deny('PRIVATE_SCHEMA_INVALID');
                unset($db);
                if (!self::privateFile($tmp,10485760)
                    || file_exists($path) || is_link($path)
                    || !@rename($tmp,$path))
                    self::deny('PRIVATE_DATABASE_INSTALL_FAILED');
                $installed[]=$path;
                $status[$kind]='created_inactive';
            }
            return [
                'ok'=>true,'code'=>'PRIVATE_DATABASES_PREPARED_INACTIVE',
                'activation'=>$status['activation'],'oauth'=>$status['oauth'],
                'memory_enabled'=>false,'mcp_enabled'=>false,
                'host_isolation_verified'=>false,
            ];
        } catch(Throwable $e) {
            // Roll back ONLY new files created by THIS invocation, never
            // existing user SQLite, host configuration or owner records.
            foreach ($installed as $path)@unlink($path);
            foreach ($temporaries as $path)@unlink($path);
            if ($e instanceof RuntimeException
                && str_starts_with($e->getMessage(),'ENGRAM_PROVISION_'))throw $e;
            self::deny('PROVISIONING_FAILED');
        } finally {
            if(is_resource($lock)){@flock($lock,LOCK_UN);@fclose($lock);}
            umask($oldMask);
        }
    }

    private static function inspectSchema(PDO $db,string $kind):string
    {
        try {
            if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')return 'invalid';
            if($db->query('PRAGMA quick_check')->fetchColumn()!=='ok')return 'invalid';
            if($kind==='activation'){
                $rows=$db->query('SELECT state,owner_binding,host_evidence_id
                    FROM activation_state WHERE singleton=1')->fetchAll(PDO::FETCH_ASSOC);
                $db->query('SELECT approval_nonce FROM activation_nonces LIMIT 1')->fetchAll();
                if(count($rows)!==1)return 'invalid';
                return ($rows[0]['state']??null)==='inactive'
                    && ($rows[0]['owner_binding']??null)===''
                    && ($rows[0]['host_evidence_id']??null)===''
                    ? 'inactive':'active_or_unknown';
            }
            $db->query('SELECT code_hash FROM mirage_oauth_codes LIMIT 1')->fetchAll();
            $db->query('SELECT token_hash FROM mirage_oauth_tokens LIMIT 1')->fetchAll();
            // Existing authorization codes/tokens must never be deleted or
            // unexpectedly reused by this setup action.
            $codes=(int)$db->query('SELECT count(*) FROM mirage_oauth_codes')->fetchColumn();
            $tokens=(int)$db->query('SELECT count(*) FROM mirage_oauth_tokens')->fetchColumn();
            return $codes===0 && $tokens===0?'inactive':'active_or_unknown';
        }catch(Throwable){return 'invalid';}
    }

    private static function privateDir(string $p):bool
    {
        clearstatcache(true,$p);$s=@lstat($p);
        return is_array($s)&&($s['mode']&0170000)===0040000
            && ($s['mode']&0077)===0 && !is_link($p) && is_dir($p);
    }

    private static function privateLock(string $p):bool
    {
        clearstatcache(true,$p);$s=@lstat($p);
        return is_array($s)&&($s['mode']&0170000)===0100000
            && ($s['mode']&0077)===0 && ($s['nlink']??0)===1
            && ($s['size']??PHP_INT_MAX)<=4096
            && !is_link($p)&&is_file($p);
    }

    private static function privateFile(string $p,int $max):bool
    {
        clearstatcache(true,$p);$s=@lstat($p);
        return is_array($s)&&($s['mode']&0170000)===0100000
            && ($s['mode']&0077)===0&&($s['nlink']??0)===1
            && ($s['size']??0)>0&&($s['size']??PHP_INT_MAX)<=$max
            && !is_link($p)&&is_file($p);
    }
    private static function deny(string $reason):never
    {
        throw new RuntimeException('ENGRAM_PROVISION_'.$reason);
    }
}
