<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramMcpHttpTransport.php';
require_once __DIR__.'/KiComEngramPrivateOwnerRegistry.php';

/**
 * DEV-48 KiCom first-party integration, initially INACTIVE in 0.9.29/0.9.30.
 * api.php may call handle() ONLY after loading the host-owned configuration
 * through kicomEngramServerRuntime(), never from a client-supplied document.
 *
 * This bridge NEVER creates activation state, token records, consent or
 * personal memories; no anonymous diagnostic route and no auto-provisioning.
 */
final class KiComEngramMcpFirstPartyBridge
{
    public static function handle(
        array $server,
        string $body,
        array $trustedRuntime,
        string $trustedWebRoot,
        int $now
    ): array {
        $deny=static fn():array=>['http_status'=>404,
            'headers'=>['Cache-Control'=>'no-store, private',
              'X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'DENY'],
            'body'=>''];
        // Fail without constructing PDO/store on the actual, unreviewed
        // 0.9.29 and 0.9.30 inactive host configurations.
        foreach (['enabled','operator_approved','host_isolation_verified',
                   'review_enabled','mcp_connector_enabled'] as $flag) {
            if (($trustedRuntime[$flag]??null)!==true) return $deny();
        }
        try {
            $web=realpath($trustedWebRoot);
            if (!$web || $web==='/' || is_link($trustedWebRoot)
                || ($trustedRuntime['web_root']??null)!==$web
                || ($trustedRuntime['runtime_source']??null)!=='server-only-reviewed'
                || ($trustedRuntime['private_memory_scope']??null)!=='dev-verified-owner') return $deny();
            $private=dirname($web).'/engram-private';
            $data=$private.'/data';
            $mcp=$private.'/mcp';
            $tokenFile=$mcp.'/mirage-mcp-token.json';
            $activationFile=$data.'/mirage-activation.sqlite';
            $registry=$private.'/owners/engram-owners.json';
            if (($trustedRuntime['data_dir']??null)!==$data
                || ($trustedRuntime['owner_registry']??null)!==$registry
                || !self::dir($private) || realpath($private)!==$private
                || !self::dir($data) || !self::dir($mcp)
                || !self::file($activationFile,10485760)) return $deny();
            // Actual owner registry was written by KiCom's existing signed
            // first-party passkey enrollment; it is read afresh each request.
            $owners=new KiComEngramPrivateOwnerRegistry($registry,$web);
            // Require a PREEXISTING activation DB: never let PDO create a
            // new writable DB merely because an HTTP request arrived.
            $activation=new PDO('sqlite:'.$activationFile,null,null,[
               PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
               PDO::ATTR_TIMEOUT=>2,
            ]);
            $activation->exec('PRAGMA busy_timeout=2000');
            $factory=static function(array $verifiedIdentity)use($data,$web,$activation):KiComEngramMcpJsonAdapter {
                // The runtime gate has already confirmed owner, activation,
                // connector, passkey registry, scopes and current revocation.
                $store=new KiComEngramStore($data,$web);
                $contract=new KiComEngramMcpContract($activation,'mirage-owner');
                return new KiComEngramMcpJsonAdapter(
                  new KiComEngramMcpController($store,$contract,'mirage-owner','active')
                );
            };
            return KiComEngramMcpHttpTransport::handle(
                $server,$body,$web,$tokenFile,$trustedRuntime,
                $activation,$owners,$now,$factory
            );
        } catch (Throwable) {
            return $deny();
        }
    }

    private static function dir(string $path):bool {
        clearstatcache(true,$path);
        $s=@lstat($path);
        return is_array($s)&&($s['mode']&0170000)===0040000
          &&($s['mode']&0077)===0&&!is_link($path)&&is_dir($path);
    }
    private static function file(string $path,int $max):bool {
        clearstatcache(true,$path);
        $s=@lstat($path);
        return is_array($s)&&($s['mode']&0170000)===0100000
          &&($s['mode']&0077)===0&&($s['nlink']??0)===1
          &&($s['size']??0)>0&&($s['size']??PHP_INT_MAX)<=$max
          &&!is_link($path)&&is_file($path);
    }
}
