<?php
declare(strict_types=1);

require_once __DIR__.'/KiComEngramOAuthHttp.php';
require_once __DIR__.'/KiComEngramMcpProtocol.php';
require_once __DIR__.'/KiComEngramPrivateOwnerRegistry.php';

/**
 * DEV-54: first-party OAuth access token -> actual MCP JSON-RPC read bridge.
 *
 * This module is NOT deployed with live 0.9.31 and does NOT register its own
 * HTTP endpoint. The original KiCom api.php router must explicitly call it
 * after kicomEngramServerRuntime(), using the server's real __DIR__, never a
 * web-request-provided path or host config.
 *
 * An OAuth client cannot self-enable this bridge, approve vhosts or issue
 * tokens. No legacy static DEV-47 token record is accepted on this route.
 */
final class KiComEngramOAuthMcpHostBridge
{
    private const BASE_HEADERS=[
        'Cache-Control'=>'no-store, private',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'Vary'=>'Accept, Authorization, MCP-Protocol-Version',
    ];

    public static function handle(
        array $server,
        string $body,
        array $trustedRuntime,
        string $trustedWebRoot,
        int $now
    ): array {
        $deny=static fn():array=>[
            'http_status'=>404,'headers'=>self::BASE_HEADERS,'body'=>''
        ];
        if(!KiComEngramOAuthHttp::available($trustedRuntime))return $deny();
        if (($server['HTTPS']??null)!=='on'
            || ($server['HTTP_HOST']??null)!=='kicom.rurtalbahn.info'
            || ($server['REQUEST_METHOD']??null)!=='POST'
            || ($server['CONTENT_TYPE']??null)!=='application/json'
            || !is_string($server['HTTP_ACCEPT']??null)
            || !str_contains($server['HTTP_ACCEPT'],'application/json')
            || !str_contains($server['HTTP_ACCEPT'],'text/event-stream')
            || (isset($server['HTTP_ORIGIN'])
                && $server['HTTP_ORIGIN']!=='https://kicom.rurtalbahn.info')
            || (isset($server['HTTP_MCP_PROTOCOL_VERSION'])
                && $server['HTTP_MCP_PROTOCOL_VERSION']!=='2025-06-18')
            || strlen($body)>8192) return $deny();

        try {
            $web=realpath($trustedWebRoot);
            if(!is_string($web) || $web==='/' || is_link($trustedWebRoot)
                || ($trustedRuntime['web_root']??null)!==$web)
                return $deny();
            $private=dirname($web).'/engram-private';
            $data=$private.'/data';
            $registry=$private.'/owners/engram-owners.json';
            $activationFile=$data.'/mirage-activation.sqlite';
            $oauthFile=$data.'/mirage-oauth.sqlite';
            if (($trustedRuntime['data_dir']??null)!==$data
                || ($trustedRuntime['owner_registry']??null)!==$registry
                || !self::dir($private) || realpath($private)!==$private
                || !self::dir($data)
                || !self::file($activationFile,10485760)
                || !self::file($oauthFile,10485760)) return $deny();

            // Existing private SQLite files only. A missing file is NEVER
            // created from a network request. Credential issuance is governed
            // by original KiCom admin+signed passkey, not this MCP route.
            $owners=new KiComEngramPrivateOwnerRegistry($registry,$web);
            $oauthDb=new PDO('sqlite:'.$oauthFile,null,null,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT=>2
            ]);
            $activation=new PDO('sqlite:'.$activationFile,null,null,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT=>2
            ]);
            $authorization=$server['HTTP_AUTHORIZATION']??null;
            $token=is_string($authorization)
                && preg_match('/\ABearer ([A-Za-z0-9_-]{43})\z/D',$authorization,$m)===1
                ? $m[1]:null;
            $identity=$token!==null
                ? KiComEngramOAuthTransactions::verify($oauthDb,$token,$now)
                : null;
            if($identity===null) {
                // The actual host still has to pass the full active/owner
                // gate before disclosing any memories or capabilities.
                return KiComEngramOAuthHttp::challenge($server,$trustedRuntime);
            }
            $factory=static function(array $verifiedIdentity)use($data,$web,$activation):KiComEngramMcpJsonAdapter {
                $store=new KiComEngramStore($data,$web);
                $contract=new KiComEngramMcpContract($activation,'mirage-owner');
                return new KiComEngramMcpJsonAdapter(
                    new KiComEngramMcpController(
                        $store,$contract,'mirage-owner','active'
                    )
                );
            };
            $result=KiComEngramMcpProtocol::handle(
                $body,$trustedRuntime,$activation,$owners,$identity,$now,$factory
            );
            $status=$result['http_status']??404;
            $payload=$result['body']??'';
            if(!is_int($status) || !in_array($status,[200,202,404],true)
                || !is_string($payload) || strlen($payload)>16384)return $deny();
            $headers=self::BASE_HEADERS;
            if($status===200){
                $headers['Content-Type']='application/json; charset=utf-8';
                $headers['MCP-Protocol-Version']='2025-06-18';
            }
            return ['http_status'=>$status,'headers'=>$headers,'body'=>$payload];
        }catch(Throwable){
            return $deny();
        }
    }

    private static function dir(string $path):bool
    {
        clearstatcache(true,$path);
        $s=@lstat($path);
        return is_array($s) && ($s['mode']&0170000)===0040000
            && ($s['mode']&0077)===0 && !is_link($path) && is_dir($path);
    }

    private static function file(string $path,int $max):bool
    {
        clearstatcache(true,$path);
        $s=@lstat($path);
        return is_array($s) && ($s['mode']&0170000)===0100000
            && ($s['mode']&0077)===0 && ($s['nlink']??0)===1
            && ($s['size']??0)>0 && ($s['size']??PHP_INT_MAX)<=$max
            && !is_link($path) && is_file($path);
    }
}
