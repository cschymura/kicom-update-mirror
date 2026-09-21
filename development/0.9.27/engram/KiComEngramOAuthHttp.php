<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramOAuthTransactions.php';

/**
 * DEV-53: first-party OAuth discovery, bearer challenge and token HTTP facade.
 *
 * This is not yet installed as a web route. Its caller supplies only a
 * trusted, operator-reviewed host policy, pinned OAuth client configuration
 * and an already-open private OAuth SQLite handle. Never populate those from
 * HTTP headers, query/body fields, GitHub, Slack or a client-provided JSON.
 *
 * No discovery, authentication prompts or token issuance on 0.9.31's
 * inactive/default engram-host.json; no personal memory is read or written.
 */
final class KiComEngramOAuthHttp
{
    public const ISSUER='https://kicom.rurtalbahn.info';
    public const RESOURCE=self::ISSUER.'/api.php?q=ENGRAM_MCP';
    public const RESOURCE_METADATA=self::ISSUER.'/.well-known/oauth-protected-resource';
    public const AUTH_METADATA=self::ISSUER.'/.well-known/oauth-authorization-server';
    public const AUTH_ENDPOINT=self::ISSUER.'/admin.php?engram_oauth=1';
    public const TOKEN_ENDPOINT=self::ISSUER.'/api.php?q=ENGRAM_OAUTH_TOKEN';

    private const HEADERS=[
        'Cache-Control'=>'no-store, private',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
    ];

    /** Server-only authorization: an HTTP request can NEVER set these flags. */
    public static function available(array $trustedHost):bool
    {
        foreach([
            'enabled','operator_approved','host_isolation_verified',
            'review_enabled','mcp_connector_enabled','oauth_enabled'
        ] as $flag)if(($trustedHost[$flag]??null)!==true)return false;
        return ($trustedHost['runtime_source']??null)==='server-only-reviewed'
            &&($trustedHost['private_memory_scope']??null)==='dev-verified-owner'
            &&($trustedHost['expected_origin']??null)===self::ISSUER
            &&($trustedHost['rp_id']??null)==='kicom.rurtalbahn.info';
    }

    public static function discovery(
        array $server,array $trustedHost,string $kind
    ):array {
        if(!self::available($trustedHost)
           || !self::httpsHost($server)
           || ($server['REQUEST_METHOD']??null)!=='GET')
            return self::result(404,'');
        if($kind==='protected_resource'){
            $data=[
                'resource'=>self::RESOURCE,
                'authorization_servers'=>[self::ISSUER],
                'scopes_supported'=>['engram.read'],
            ];
        }elseif($kind==='authorization_server'){
            $data=[
                'issuer'=>self::ISSUER,
                'authorization_endpoint'=>self::AUTH_ENDPOINT,
                'token_endpoint'=>self::TOKEN_ENDPOINT,
                'response_types_supported'=>['code'],
                'grant_types_supported'=>['authorization_code'],
                'code_challenge_methods_supported'=>['S256'],
                'scopes_supported'=>['engram.read'],
                'token_endpoint_auth_methods_supported'=>['none'],
                // ChatGPT's pinned client is provisioned separately. Do not
                // advertise CIMD until actual HTTPS client document
                // validation is implemented; no dynamic registration.
                'client_id_metadata_document_supported'=>false,
            ];
        }else return self::result(404,'');
        return self::json(200,$data);
    }

    /**
     * For an actually available resource, challenge missing/bad OAuth tokens
     * so ChatGPT can discover and initiate the authorization-code flow.
     * Inactive real hosts receive 404 instead of advertising a broken login.
     */
    public static function challenge(array $server,array $trustedHost):array
    {
        if(!self::available($trustedHost)||!self::httpsHost($server))
            return self::result(404,'');
        $headers=self::HEADERS;
        $headers['WWW-Authenticate']='Bearer resource_metadata="'.self::RESOURCE_METADATA
            .'", scope="engram.read", error="invalid_token", error_description="Authorization required"';
        return ['http_status'=>401,'headers'=>$headers,'body'=>''];
    }

    /**
     * This token endpoint MUST be reachable without an MCP bearer, because
     * its public-client authorization is the single-use code + PKCE S256.
     * Exact previously pinned client/redirect/resource are rechecked in the
     * private OAuthTransactions::exchange() method.
     */
    public static function token(
        array $server,string $raw,array $trustedHost,
        array $trustedClient,PDO $privateOAuthDb,int $now
    ):array {
        if(!self::available($trustedHost)
            || !self::httpsHost($server)
            || ($server['REQUEST_METHOD']??null)!=='POST'
            || !in_array(strtolower((string)($server['CONTENT_TYPE']??'')),
                ['application/x-www-form-urlencoded',
                 'application/x-www-form-urlencoded; charset=utf-8'],true)
            || array_key_exists('HTTP_AUTHORIZATION',$server)
            || (isset($server['HTTP_ORIGIN'])
                && $server['HTTP_ORIGIN']!==self::ISSUER))
            return self::result(404,'');
        try{
            $form=self::strictForm($raw);
            $token=KiComEngramOAuthTransactions::exchange(
                $privateOAuthDb,$form,$trustedClient,$now
            );
            return self::json(200,$token);
        }catch(Throwable){
            // Never leak whether a code, client, PKCE, private path or
            // runtime policy failed.
            return self::json(400,['error'=>'invalid_grant']);
        }
    }

    /** Exactly the expected six form fields; no PHP bracket/dot normalization. */
    private static function strictForm(string $raw):array
    {
        if($raw===''||strlen($raw)>2048)throw new RuntimeException('INVALID_FORM');
        $allowed=[
            'grant_type','code','code_verifier','redirect_uri','client_id','resource'
        ];
        $out=[];
        foreach(explode('&',$raw)as $pair){
            if(!str_contains($pair,'='))throw new RuntimeException('INVALID_FORM');
            [$key,$value]=explode('=',$pair,2);
            if(!in_array($key,$allowed,true)||array_key_exists($key,$out)
                || strlen($value)>1024 || preg_match('/%(?![0-9a-fA-F]{2})/',$value))
                throw new RuntimeException('INVALID_FORM');
            $decoded=urldecode($value);
            if(str_contains($decoded,"\0")||str_contains($decoded,"\r")
                || str_contains($decoded,"\n"))throw new RuntimeException('INVALID_FORM');
            $out[$key]=$decoded;
        }
        if(count($out)!==count($allowed))throw new RuntimeException('INVALID_FORM');
        return $out;
    }

    private static function httpsHost(array $server):bool
    {
        return ($server['HTTPS']??null)==='on'
            &&($server['HTTP_HOST']??null)==='kicom.rurtalbahn.info';
    }

    private static function result(int $status,string $body):array
    {
        return ['http_status'=>$status,'headers'=>self::HEADERS,'body'=>$body];
    }
    private static function json(int $status,array $data):array
    {
        $headers=self::HEADERS;
        $headers['Content-Type']='application/json; charset=utf-8';
        return ['http_status'=>$status,'headers'=>$headers,
            'body'=>json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
    }
}
