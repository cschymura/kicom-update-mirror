<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramOAuthTransactions.php';
require_once __DIR__.'/KiComEngramOAuthHttp.php';
require_once __DIR__.'/KiComEngramAdminSameOriginNavigation.php';

/**
 * Explicit, ONE-TIME operator-initiated migration of an EXISTING active
 * private OAuth SQLite file. Never run from a token endpoint or GET.
 * Existing access tokens, passkeys, consents, owner data and code rows stay intact.
 */
final class KiComEngramAdminRefreshMigration
{
    private const H=['Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY','Referrer-Policy'=>'no-referrer'];

    public static function handle(array $server,array $get,array $post,array $session,
        string $sessionId,string $csrf,array $runtime,string $webRoot):array
    {
        $deny=static fn(int $status):array=>['http_status'=>$status,'headers'=>self::H,'body'=>''];
        if($get!==['engram_refresh_setup'=>'1']
            || ($server['HTTPS']??null)!=='on'
            || ($server['HTTP_HOST']??null)!=='kicom.rurtalbahn.info'
            || ($session['admin']??null)!==true
            || !is_string($session['csrf']??null)
            || !hash_equals($session['csrf'],$csrf)
            || strlen($sessionId)<24
            || !KiComEngramOAuthHttp::available($runtime)
            || ($runtime['web_root']??null)!==realpath($webRoot))return $deny(404);
        $method=$server['REQUEST_METHOD']??'';
        if($method!=='GET'&&$method!=='POST')return $deny(405);
        if($method==='GET')return self::page('Vorbereitung bereit',htmlspecialchars($csrf,ENT_QUOTES,'UTF-8'));
        if(!KiComEngramAdminSameOriginNavigation::permits($server)
            || count($post)!==2
            || !isset($post['csrf'],$post['confirmation'])
            || !is_string($post['csrf'])
            || !hash_equals($csrf,$post['csrf'])
            || $post['confirmation']!=='FREIGABE')return $deny(403);
        try {
            $result=self::migrate($runtime,$webRoot);
            return self::page($result==='already'?'Bereits vorbereitet':'Erfolgreich vorbereitet',
                htmlspecialchars($csrf,ENT_QUOTES,'UTF-8'));
        }catch(Throwable){return self::page('Vorbereitung fehlgeschlagen – keine Zugriffsrechte verändert',
            htmlspecialchars($csrf,ENT_QUOTES,'UTF-8'),409);}
    }

    private static function migrate(array $runtime,string $webRoot):string
    {
        $web=realpath($webRoot);
        if(!is_string($web)||$web==='/'||is_link($webRoot))throw new RuntimeException('ROOT_INVALID');
        $private=dirname($web).'/engram-private';
        $data=$private.'/data';$backups=$private.'/backups';
        if(($runtime['data_dir']??null)!==$data
            ||($runtime['backups_dir']??null)!==$backups
            ||!self::dir($private)||!self::dir($data)||!self::dir($backups)
            ||realpath($private)!==$private||realpath($data)!==$data
            ||realpath($backups)!==$backups)throw new RuntimeException('PRIVATE_ROOT_INVALID');
        $file=$data.'/mirage-oauth.sqlite';
        if(!self::file($file,10485760))throw new RuntimeException('OAUTH_FILE_INVALID');
        $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT=>2]);
        if($db->query('PRAGMA quick_check')->fetchColumn()!=='ok')throw new RuntimeException('QUICK_CHECK_FAILED');
        $codes=(int)$db->query('SELECT count(*) FROM mirage_oauth_codes')->fetchColumn();
        $tokens=(int)$db->query('SELECT count(*) FROM mirage_oauth_tokens')->fetchColumn();
        $exists=$db->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='mirage_oauth_refresh_tokens'")->fetchColumn();
        if((int)$exists===1)return 'already';
        if((int)$exists!==0)throw new RuntimeException('SCHEMA_UNEXPECTED');
        $backup=$backups.'/mirage-oauth-before-refresh-'.bin2hex(random_bytes(12)).'.sqlite';
        if(file_exists($backup)||is_link($backup))throw new RuntimeException('BACKUP_COLLISION');
        $old=umask(0077);
        try {
            $db->exec('VACUUM INTO '.$db->quote($backup));
            @chmod($backup,0600);
            if(!self::file($backup,10485760))throw new RuntimeException('BACKUP_INVALID');
            $snapshot=new PDO('sqlite:'.$backup,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            if($snapshot->query('PRAGMA quick_check')->fetchColumn()!=='ok'
                ||(int)$snapshot->query('SELECT count(*) FROM mirage_oauth_codes')->fetchColumn()!==$codes
                ||(int)$snapshot->query('SELECT count(*) FROM mirage_oauth_tokens')->fetchColumn()!==$tokens)
                throw new RuntimeException('BACKUP_CHECK_FAILED');
            unset($snapshot);
            $db->exec('BEGIN IMMEDIATE');
            try {
                KiComEngramOAuthTransactions::prepareRefreshSchema($db);
                if($db->query('PRAGMA quick_check')->fetchColumn()!=='ok'
                    ||(int)$db->query('SELECT count(*) FROM mirage_oauth_codes')->fetchColumn()!==$codes
                    ||(int)$db->query('SELECT count(*) FROM mirage_oauth_tokens')->fetchColumn()!==$tokens)
                    throw new RuntimeException('MIGRATION_CHECK_FAILED');
                $db->exec('COMMIT');
            }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
            return 'prepared';
        }finally{umask($old);}
    }
    private static function dir(string $path):bool
    {
        clearstatcache(true,$path);$s=@lstat($path);
        return is_array($s)&&($s['mode']&0170000)===0040000
            &&($s['mode']&0077)===0&&!is_link($path)&&is_dir($path);
    }
    private static function file(string $path,int $max):bool
    {
        clearstatcache(true,$path);$s=@lstat($path);
        return is_array($s)&&($s['mode']&0170000)===0100000
            &&($s['mode']&0077)===0&&($s['nlink']??0)===1
            &&($s['size']??0)>0&&($s['size']??PHP_INT_MAX)<=$max
            &&!is_link($path)&&is_file($path);
    }
    private static function page(string $message,string $csrf,int $status=200):array
    {
        $text=htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $form=$message==='Vorbereitung bereit'
            ? '<form method="post" action="admin.php?engram_refresh_setup=1">'
                .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                .'<button type="submit" name="confirmation" value="FREIGABE">FREIGABE</button></form>'
            : '';
        $html='<!doctype html><html lang="de"><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>KiCom – Verbindung vorbereiten</title><main>'
            .'<h1>Verbindung vorbereiten</h1><p role="status">'.$text.'</p>'
            .$form.'<p><a href="admin.php">Zurück</a></p></main></html>';
        return ['http_status'=>$status,'headers'=>self::H+['Content-Type'=>'text/html; charset=utf-8'],
            'body'=>$html];
    }
}
