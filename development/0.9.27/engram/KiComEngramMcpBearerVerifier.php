<?php
declare(strict_types=1);

/**
 * DEV-47: derive MCP connector identity ONLY from a separately provisioned
 * PRIVATE operator-owned bearer-token hash record.
 *
 * This does not issue tokens, read a passkey assertion, authorize activation,
 * or modify KiCom's existing passkey/owner registry.
 */
final class KiComEngramMcpBearerVerifier
{
    public static function verify(
        string $trustedRecordPath,
        string $trustedWebRoot,
        string $authorizationHeader,
        int $now
    ): ?array {
        $web=realpath($trustedWebRoot);
        $dir=realpath(dirname($trustedRecordPath));
        if (!$web || !$dir || $web==='/' || !is_dir($web)
            || $dir===$web || str_starts_with($dir,$web.DIRECTORY_SEPARATOR)
            || is_link(dirname($trustedRecordPath))
            || basename($trustedRecordPath)!=='mirage-mcp-token.json'
            || realpath($trustedRecordPath)!==$dir.'/mirage-mcp-token.json'
            || !self::protectedFile($dir.'/mirage-mcp-token.json')
            || !self::protectedDir($dir)) return null;
        if (!preg_match('/\ABearer ([A-Za-z0-9_-]{43})\z/D',$authorizationHeader,$match)) return null;
        $raw=@file_get_contents($trustedRecordPath,false,null,0,4097);
        if (!is_string($raw) || strlen($raw)<100 || strlen($raw)>4096) return null;
        try{$record=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
        catch(Throwable){return null;}
        if (!is_array($record)||array_is_list($record))return null;
        $required=[
            'schema','enabled','token_sha256','connector_id',
            'credential_fingerprint','owner_binding','host_evidence_id',
            'issued_at','expires_at'
        ];
        $actual=array_keys($record);sort($actual,SORT_STRING);sort($required,SORT_STRING);
        if ($actual!==$required || $record['schema']!==1 || $record['enabled']!==true) return null;
        foreach (['token_sha256','credential_fingerprint','owner_binding','host_evidence_id'] as $key) {
            if (!is_string($record[$key])
                || preg_match('/\A[a-f0-9]{64}\z/D',$record[$key])!==1) return null;
        }
        $id=$record['connector_id'];
        if (!is_string($id)||preg_match('/\A[a-z0-9][a-z0-9._:-]{2,63}\z/D',$id)!==1) return null;
        if (!is_int($record['issued_at']) || !is_int($record['expires_at'])
            || $record['issued_at']>$now || $now>=$record['expires_at']
            || $record['expires_at']<=$record['issued_at']
            || $record['expires_at']-$record['issued_at']>604800) return null;
        // Fixed-format base64url 32 random bytes; hash only stored at rest.
        $token=$match[1];
        $bytes=base64_decode(strtr($token,'-_','+/').'=',true);
        if (!is_string($bytes) || strlen($bytes)!==32
            || rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')!==$token
            || !hash_equals($record['token_sha256'],hash('sha256',$token))) return null;
        return [
            'authenticated'=>true,
            'connector_id'=>$id,
            'credential_fingerprint'=>$record['credential_fingerprint'],
            'owner_binding'=>$record['owner_binding'],
            'host_evidence_id'=>$record['host_evidence_id'],
        ];
    }
    private static function protectedDir(string $path):bool{
        clearstatcache(true,$path);$s=@lstat($path);
        return is_array($s)&&($s['mode']&0170000)===0040000
            &&($s['mode']&0077)===0 && !is_link($path);
    }
    private static function protectedFile(string $path):bool{
        clearstatcache(true,$path);$s=@lstat($path);
        return is_array($s)&&($s['mode']&0170000)===0100000
            &&($s['mode']&0077)===0 &&($s['nlink']??0)===1
            &&($s['size']??0)>100 &&($s['size']??0)<=4096
            &&!is_link($path);
    }
}
