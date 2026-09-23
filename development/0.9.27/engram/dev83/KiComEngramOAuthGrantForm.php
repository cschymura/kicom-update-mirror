<?php
declare(strict_types=1);

/**
 * DEV-79: Strict, server-pinned OAuth token grant form parser.
 * Call only after original HTTPS/host/runtime/POST checks in OAuthHttp::token.
 * Does not issue, verify, renew or authorize a token; cannot grant extra scopes.
 */
final class KiComEngramOAuthGrantForm
{
    private const RESOURCE='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
    private const CODE_KEYS=['grant_type','code','code_verifier','redirect_uri','client_id','resource'];
    private const REFRESH_KEYS=['grant_type','refresh_token','client_id'];
    private const READ_SCOPE='engram.read';

    /** @return array{kind:string,arguments:array<string,string>} */
    public static function parse(string $raw,array $trustedClient):array
    {
        if ($raw==='' || strlen($raw)>2048 || !isset($trustedClient['client_id'])
            || !is_string($trustedClient['client_id']))self::reject();
        $out=[];
        foreach(explode('&',$raw) as $pair){
            if(!str_contains($pair,'='))self::reject();
            [$key,$value]=explode('=',$pair,2);
            if(!in_array($key,['grant_type','code','code_verifier','redirect_uri',
                'client_id','resource','refresh_token','scope'],true)
                || array_key_exists($key,$out) || strlen($value)>1024
                || preg_match('/%(?![A-Fa-f0-9]{2})/',$value))self::reject();
            $value=urldecode($value);
            if(preg_match('/[\x00-\x1f\x7f]/',$value))self::reject();
            $out[$key]=$value;
        }
        if(($out['client_id']??null)!==$trustedClient['client_id'])self::reject();
        // If provided, audience must match the pinned resource. Refresh may omit it:
        // the server-owned refresh row already binds the sole approved audience.
        if(array_key_exists('resource',$out) && $out['resource']!==self::RESOURCE)self::reject();
        if(($out['grant_type']??null)==='authorization_code'){
            self::keys($out,self::CODE_KEYS);
            return ['kind'=>'authorization_code','arguments'=>$out];
        }
        if(($out['grant_type']??null)==='refresh_token'){
            $required=self::REFRESH_KEYS;
            if(array_key_exists('resource',$out))$required[]='resource';
            if(array_key_exists('scope',$out)){
                if($out['scope']!==self::READ_SCOPE)self::reject();
                $required[]='scope';
            }
            self::keys($out,$required);
            if(!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$out['refresh_token']))self::reject();
            // Refresh permissions always come from the trusted server-side row.
            return ['kind'=>'refresh_token','arguments'=>['refresh_token'=>$out['refresh_token']]];
        }
        self::reject();
    }
    private static function keys(array $out,array $required):void
    {
        $actual=array_keys($out);sort($actual,SORT_STRING);sort($required,SORT_STRING);
        if($actual!==$required)self::reject();
    }
    private static function reject():never
    {
        throw new RuntimeException('OAUTH_TOKEN_GRANT_FORM_REJECTED');
    }
}
