<?php
declare(strict_types=1);

/**
 * DEV-95: Additive operator-only UPGRADE of EXISTING ACTIVE private databases.
 *
 * This is NOT an HTTP handler, token issuer, or authorization mechanism.
 * The ONLY permitted caller is the original authenticated admin route AFTER
 * original CSRF and fresh owner Passkey verification. Its runtime and root
 * MUST be resolved server-side, never from a posted path/flag. Schema DDL and
 * secret generation are forbidden in MCP/OAuth/GET handlers.
 */
final class KiComEngramActiveSchemaUpgrade
{
    private const MAX_DB_BYTES=10485760;
    private const MAX_BACKUP_BYTES=16777216;
    private const OAUTH_DB='mirage-oauth.sqlite';
    private const STORE_DB='engrams.sqlite';
    private const SIGNING_KEY='mirage-write-signing.key';

    private static function refuse():never {
        throw new RuntimeException('ACTIVE_SCHEMA_UPGRADE_DENIED');
    }
    private static function privateDir(string $path):void {
        $s=@lstat($path);
        if(!is_array($s)||($s['mode']&0170000)!==0040000
           ||($s['mode']&0077)!==0||is_link($path)
           ||realpath($path)!==$path)self::refuse();
    }
    private static function privateFile(string $path,int $max):void {
        clearstatcache(true,$path);$s=@lstat($path);
        if(!is_array($s)||($s['mode']&0170000)!==0100000
           ||($s['mode']&0077)!==0||($s['nlink']??0)!==1
           ||($s['size']??0)<1||($s['size']??PHP_INT_MAX)>$max
           ||is_link($path)||realpath($path)!==$path)self::refuse();
    }
    /** Runtime may ONLY be the result of original kicomEngramServerRuntime(). */
    private static function fixedPaths(string $trustedWebRoot,array $runtime):array {
        $web=realpath($trustedWebRoot);
        if(!is_string($web)||$web==='/'||is_link($trustedWebRoot)
            ||!is_file($web.'/admin.php')||!is_file($web.'/lib.php'))self::refuse();
        $private=dirname($web).'/engram-private';
        $data=$private.'/data';$backups=$private.'/backups';
        foreach([$private,$data,$backups] as $dir)self::privateDir($dir);
        if(($runtime['web_root']??null)!==$web
            ||($runtime['data_dir']??null)!==$data
            ||($runtime['backups_dir']??null)!==$backups
            ||($runtime['enabled']??null)!==true
            ||($runtime['operator_approved']??null)!==true
            ||($runtime['oauth_enabled']??null)!==true
            ||($runtime['mcp_connector_enabled']??null)!==true
            ||($runtime['rp_id']??null)!=='kicom.rurtalbahn.info'
            ||($runtime['expected_origin']??null)!=='https://kicom.rurtalbahn.info'
            ||($runtime['admin_subject']??null)!=='mirage-owner'
            ||!is_string($runtime['owner_binding']??null)
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$runtime['owner_binding']))
            self::refuse();
        $oauth=$data.'/'.self::OAUTH_DB;
        $store=$data.'/'.self::STORE_DB;
        self::privateFile($oauth,self::MAX_DB_BYTES);
        self::privateFile($store,self::MAX_DB_BYTES);
        return [$data,$backups,$oauth,$store];
    }

    /** Ensure the table not only has columns but also its replay-binding UNIQUE key. */
    private static function uniqueIndex(PDO $db,string $table,array $columns):void {
        if(!in_array($table,['mirage_oauth_refresh_tokens','mirage_oauth_write_consents'],true))
            self::refuse();
        $indexes=$db->query('PRAGMA index_list('.$table.')')->fetchAll(PDO::FETCH_ASSOC);
        foreach($indexes as $index) {
            if((int)($index['unique']??0)!==1 || (int)($index['partial']??0)!==0)
                continue;
            $name=$index['name']??null;
            if(!is_string($name)||!preg_match('/\\A[a-zA-Z0-9_]+\\z/D',$name))
                continue;
            $actual=$db->query('PRAGMA index_info('.$name.')')
                ->fetchAll(PDO::FETCH_COLUMN,2);
            if($actual===$columns)return;
        }
        self::refuse();
    }

    /** READ-ONLY preflight. No schema DDL, key, file, token, scope, or runtime writes. */
    public static function preflight(string $webRoot,array $trustedRuntime):array {
        [$data,$backups,$oauth,$store]=self::fixedPaths($webRoot,$trustedRuntime);
        foreach([$oauth,$store] as $p) {
            $db=self::open($p);
            if($db->query('PRAGMA quick_check')->fetchColumn()!=='ok')self::refuse();
        }
        $key=$data.'/'.self::SIGNING_KEY;
        if(file_exists($key)||is_link($key))self::privateFile($key,128);
        return ['ok'=>true,'code'=>'ACTIVE_SCHEMA_UPGRADE_PREFLIGHT_OK',
            'will_activate_write'=>false,'will_change_oauth_scopes'=>false];
    }

    private static function open(string $path):PDO {
        self::privateFile($path,self::MAX_DB_BYTES);
        $db=new PDO('sqlite:'.$path,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3
        ]);
        $db->exec('PRAGMA busy_timeout = 3000');
        $db->exec('PRAGMA foreign_keys = ON');
        return $db;
    }

    /** A consistent SQLite snapshot, not a dangerous raw copy of a WAL DB. */
    private static function snapshot(PDO $db,string $original,string $backups):string {
        $id=bin2hex(random_bytes(16));
        $name=$backups.'/dev95-'.$id.'-'.basename($original);
        if(file_exists($name)||is_link($name))self::refuse();
        $mask=umask(0077);
        try {
            // Destination is a fixed private directory and random basename;
            // PDO::quote never accepts a user-supplied filesystem path.
            $db->exec('VACUUM main INTO '.$db->quote($name));
        } finally {umask($mask);}
        @chmod($name,0600);
        self::privateFile($name,self::MAX_BACKUP_BYTES);
        $check=new PDO('sqlite:'.$name,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
        ]);
        if($check->query('PRAGMA quick_check')->fetchColumn()!=='ok')self::refuse();
        $check=null;
        return $name;
    }

    /**
     * Explicit one-time admin/Passkey-confirmed action. Additive only.
     * Existing data and original read tokens are never deleted or rewritten.
     * Independent SQLite transactions cannot span both DBs: on failure
     * backups remain private and the operator must resolve the partial state;
     * no write runtime flag is set automatically.
     */
    public static function applyAfterVerifiedPasskey(
        string $webRoot,array $trustedRuntime
    ):array {
        [$data,$backups,$oauthPath,$engramPath]=self::fixedPaths($webRoot,$trustedRuntime);
        $lockPath=$backups.'/.engram-schema-upgrade.lock';
        if(is_link($lockPath))self::refuse();
        if(file_exists($lockPath))self::privateFile($lockPath,128);
        $mask=umask(0077);
        try {$lock=@fopen($lockPath,'c+b');}
        finally {umask($mask);}
        if(!is_resource($lock))self::refuse();
        try {
            if(!flock($lock,LOCK_EX|LOCK_NB))self::refuse();
            if(fstat($lock)['size']===0) {
                if(fwrite($lock,'1')!==1||!fflush($lock))self::refuse();
            }
            @chmod($lockPath,0600);
            self::privateFile($lockPath,128);
            $oauth=self::open($oauthPath);
            $engram=self::open($engramPath);
            foreach([$oauth,$engram] as $db)
                if($db->query('PRAGMA quick_check')->fetchColumn()!=='ok')self::refuse();

            // Both completed SQLite backups MUST exist BEFORE any DDL.
            $oauthBackup=self::snapshot($oauth,$oauthPath,$backups);
            $engramBackup=self::snapshot($engram,$engramPath,$backups);
            self::privateFile($oauthBackup,self::MAX_BACKUP_BYTES);
            self::privateFile($engramBackup,self::MAX_BACKUP_BYTES);
            KiComEngramOAuthTransactions::prepareWriteSchema($oauth);
            KiComEngramOAuthTransactions::prepareRefreshSchema($oauth);
            KiComEngramMutationSchema::prepareNew($engram);
            KiComEngramMutationSchema::assertReady($engram);
            $required=['refresh_hash','client_id','connector_id','host_evidence_id',
                'resource','scope','owner_binding','credential_fingerprint',
                'issued_at','expires_at','consumed','revoked','parent_access_hash'];
            $have=$oauth->query('PRAGMA table_info(mirage_oauth_refresh_tokens)')
                ->fetchAll(PDO::FETCH_COLUMN,1);
            if($have!==$required)self::refuse();
            self::uniqueIndex($oauth,'mirage_oauth_refresh_tokens',['parent_access_hash']);
            $consentCols=$oauth->query('PRAGMA table_info(mirage_oauth_write_consents)')
                ->fetchAll(PDO::FETCH_COLUMN,1);
            if($consentCols!==['consent_ref','owner','namespace','client_id',
                'connector_id','owner_binding','credential_fingerprint','source_kind',
                'approved_at','revoked_at','token_hash'])self::refuse();
            self::uniqueIndex($oauth,'mirage_oauth_write_consents',['token_hash']);
            foreach([$oauth,$engram] as $db)
                if($db->query('PRAGMA quick_check')->fetchColumn()!=='ok')self::refuse();

            $key=$data.'/'.self::SIGNING_KEY;
            if(file_exists($key)||is_link($key)) {
                self::privateFile($key,128);
                $value=@file_get_contents($key);
                if(!is_string($value)||preg_match('/\A[a-f0-9]{64}\z/D',$value)!==1)
                    self::refuse();
            } else {
                $mask=umask(0077);
                try {
                    $fh=@fopen($key,'x+b');
                    if(!is_resource($fh))self::refuse();
                    try {
                        $bytes=bin2hex(random_bytes(32));
                        if(fwrite($fh,$bytes)!==strlen($bytes)||!fflush($fh))
                            self::refuse();
                    } finally {fclose($fh);}
                } finally {umask($mask);}
                @chmod($key,0600);
                self::privateFile($key,128);
            }
            return ['ok'=>true,'code'=>'PRIVATE_SCHEMAS_PREPARED',
                'oauth_backup_created'=>true,'engram_backup_created'=>true,
                'existing_read_tokens_preserved'=>true,
                'write_scope_activated'=>false,'runtime_modified'=>false];
        } finally {
            if(is_resource($lock)){@flock($lock,LOCK_UN);fclose($lock);}
        }
    }
}
