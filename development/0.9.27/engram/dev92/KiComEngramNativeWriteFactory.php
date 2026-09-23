<?php
declare(strict_types=1);

/**
 * DEV-92: first-party native MCP WRITE factory, invoked ONLY after the actual
 * KiComEngramOAuthMcpHostBridge combined-token/active-owner/consent gate.
 * No web/request callback may supply any dependency or identity.
 *
 * Native package must require the existing DEV-72/75/76/87/88/89 components
 * from the SAME modules/engram/ directory before invoking build().
 * This factory neither creates SQLite files/tables nor issues OAuth scopes.
 */
final class KiComEngramNativeWriteFactory
{
    private const OWNER='mirage-owner';
    private const SPACE='project';

    /** @return ?callable(string,array,string,int):array */
    public static function build(
        PDO $oauthDb,string $bearer,array $verifiedCombined,
        array $approved,array $serverRuntime,string $trustedWebRoot,int $now
    ):?callable {
        // Production feature is DEFAULT OFF until independently approved and
        // provisioned through original first-party KiCom administration.
        if(($serverRuntime['engram_write_enabled']??null)!==true
            || ($serverRuntime['operator_approved']??null)!==true
            || ($verifiedCombined['authenticated']??null)!==true
            || !is_string($verifiedCombined['credential_fingerprint']??null)
            || !preg_match('/\A[a-f0-9]{64}\z/D',$verifiedCombined['credential_fingerprint'])
            || !is_string($verifiedCombined['connector_id']??null)
            || !preg_match('/\A[a-z0-9][a-z0-9._:-]{2,63}\z/D',$verifiedCombined['connector_id'])
            || !is_string($verifiedCombined['owner_binding']??null)
            || !hash_equals(hash('sha256',self::OWNER."\0".$verifiedCombined['credential_fingerprint']),
                $verifiedCombined['owner_binding'])
            || !is_string($bearer)||preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$bearer)!==1
            || !is_array($approved)
            || array_keys($approved)!==['source_kind','source_ref']
            || !in_array($approved['source_kind'],['explicit_user','approved_summary','verified_checkpoint'],true)
            || !is_string($approved['source_ref'])
            || preg_match('/\Aconsent:[a-f0-9]{64}\z/D',$approved['source_ref'])!==1
            || $oauthDb->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'
            || $now<1)return null;

        $web=realpath($trustedWebRoot);
        if(!is_string($web)||$web==='/'||($serverRuntime['web_root']??null)!==$web
            || is_link($trustedWebRoot))return null;
        $data=dirname($web).'/engram-private/data';
        if(($serverRuntime['data_dir']??null)!==$data || !self::privateDir($data))
            return null;

        // Both are preexisting operator-provisioned private files, fixed paths.
        // An OAuth/MCP HTTP call MUST NOT bootstrap either DB or its signing key.
        $dbFile=$data.'/engrams.sqlite';
        $secretFile=$data.'/mirage-write-signing.key';
        if(!self::privateFile($dbFile,10485760)
            || !self::privateFile($secretFile,128))return null;
        $secret=@file_get_contents($secretFile);
        if(!is_string($secret)||preg_match('/\A[a-f0-9]{64}\z/D',$secret)!==1)
            return null;
        try {
            $engramDb=new PDO('sqlite:'.$dbFile,null,null,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT=>2,
                PDO::ATTR_EMULATE_PREPARES=>false
            ]);
            $store=new KiComEngramRevisionAdapter($engramDb);
            $reader=new KiComEngramPrivateWriteConsentReader($oauthDb);
            $grants=new KiComEngramWriteGrant($secret);
        }catch(Throwable){return null;}

        $tokenHash=hash('sha256',$bearer);
        $fp=$verifiedCombined['credential_fingerprint'];
        $connector=$verifiedCombined['connector_id'];
        $sourceKind=$approved['source_kind'];
        $sourceRef=$approved['source_ref'];
        $lookup=static fn(array $claim):bool=>$reader->verify($claim,time());

        $service=new KiComEngramCanonicalMutationService($grants,$store,$lookup);
        $controller=new KiComEngramCanonicalMcpMutationController($service);
        $oauth=[
            'authenticated'=>true,'owner'=>self::OWNER,'namespace'=>self::SPACE,
            'connector_id'=>$connector,'token_fingerprint'=>$tokenHash,
            'scopes'=>['engram.read','engram.write'],
            'server_provenance'=>[
                'source_kind'=>$sourceKind,'source_ref'=>$sourceRef,'verified'=>true,
                'owner'=>self::OWNER,'namespace'=>self::SPACE,
                'token_fingerprint'=>$tokenHash
            ]
        ];

        return static function(string $operation,array $input,string $idem,int $calledAt)
            use($controller,$grants,$oauth,$secret,$tokenHash):array {
            if(!in_array($operation,['engram_write','engram_update','engram_archive'],true)
                || !preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/D',$idem)
                || abs(time()-$calledAt)>30)throw new RuntimeException('INVALID_NATIVE_WRITE_CALL');

            // The nonce is stable for an EXACT request identity, even when a
            // restarted PHP worker performs an idempotent retry. Do not derive
            // it from fresh random_bytes or the retry would be rejected.
            $nonce=hash_hmac('sha256',$tokenHash."\0".$operation."\0".$idem,$secret);
            $grant=$grants->issueSynthetic([
                'v'=>1,'grant_id'=>bin2hex(random_bytes(16)),
                'owner'=>'mirage-owner','namespace'=>'project',
                'connector_id'=>$oauth['connector_id'],
                'token_fingerprint'=>$tokenHash,
                'operations'=>[$operation],'nonce'=>$nonce,
                'iat'=>$calledAt-1,'exp'=>$calledAt+60
            ]);
            return $controller->handle($oauth,[
                'tool'=>$operation,'write_grant'=>$grant,
                'idempotency_key'=>$idem,'input'=>$input
            ],$calledAt);
        };
    }
    private static function privateDir(string $dir):bool
    {
        clearstatcache(true,$dir);$s=@lstat($dir);
        return is_array($s)&&($s['mode']&0170000)===0040000
            &&($s['mode']&0077)===0&&!is_link($dir)&&is_dir($dir);
    }
    private static function privateFile(string $file,int $max):bool
    {
        clearstatcache(true,$file);$s=@lstat($file);
        return is_array($s)&&($s['mode']&0170000)===0100000
            &&($s['mode']&0077)===0&&($s['nlink']??0)===1
            &&($s['size']??0)>0&&($s['size']??PHP_INT_MAX)<=$max
            &&!is_link($file)&&is_file($file);
    }
}
