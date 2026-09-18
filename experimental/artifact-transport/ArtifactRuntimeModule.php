<?php
declare(strict_types=1);

require_once __DIR__.'/ArtifactTransportBus.php';
require_once __DIR__.'/ArtifactChannelResolver.php';
require_once __DIR__.'/ArtifactKiComBridge.php';
require_once __DIR__.'/ArtifactFtpAdapter.php';

/**
 * Runtime glue for KiCom's existing update controller.
 *
 * No installer is implemented here. Verified artifacts are handed exclusively
 * to KiComArtifactRuntimeBridge -> kicomReceiveSelfUpdatePackage().
 */
final class KiComArtifactRuntimeModule
{
    /** @return array<string,mixed> */
    public static function status(): array
    {
        $sources=self::sources();
        return [
            'ok'=>true,
            'code'=>'ARTIFACT_RUNTIME_READY',
            'module'=>'artifact-transport',
            'authority'=>'transport-only',
            'duplicates_installer'=>false,
            'receiver'=>KiComArtifactRuntimeBridge::status(),
            'sources'=>array_map(static fn(array $s):array=>[
                'id'=>(string)($s['id']??''),
                'type'=>(string)($s['type']??''),
                'priority'=>(int)($s['priority']??0),
                'enabled'=>!empty($s['enabled']),
            ],$sources),
            'offline_descriptor'=>'artifact.json',
        ];
    }

    /**
     * Called by the small core hook only after the built-in pull channels found
     * no usable candidate. It is therefore a fallback, not a competing updater.
     * @return array<string,mixed>
     */
    public static function fallbackPull(bool $allowAuto=true): array
    {
        if(!defined('KICOM_VERSION')) return ['ok'=>false,'code'=>'ARTIFACT_RUNTIME_VERSION_MISSING'];
        if(!function_exists('kicomVarDir')||!function_exists('kicomReceiveSelfUpdatePackage')) return ['ok'=>false,'code'=>'ARTIFACT_RUNTIME_KICOM_MISSING'];

        $sources=self::sources();
        $artifact=null;$discovery=null;
        $offline=self::offlineDescriptor();
        if(!empty($offline['ok'])&&version_compare((string)$offline['artifact']['version'],(string)KICOM_VERSION,'>')){
            $artifact=$offline['artifact'];
            $discovery=['ok'=>true,'code'=>'ARTIFACT_OFFLINE_DESCRIPTOR_SELECTED','artifact'=>$artifact,'evidence_sources'=>['local-inbox-descriptor']];
        }else{
            $resolver=new KiComArtifactChannelResolver($sources);
            $discovery=$resolver->discoverLatest((string)KICOM_VERSION);
            if(empty($discovery['ok'])) return $discovery+['module'=>'artifact-transport'];
            if(($discovery['code']??'')==='ARTIFACT_NO_UPDATE') return $discovery+['module'=>'artifact-transport'];
            $artifact=is_array($discovery['artifact']??null)?$discovery['artifact']:null;
        }
        if(!is_array($artifact)) return ['ok'=>false,'code'=>'ARTIFACT_DISCOVERY_RESULT_INVALID'];

        $store=rtrim(kicomVarDir(),'/').'/artifact_transport';
        $inbox=$store.'/inbox';
        $adapters=self::adapters();
        try{
            $bus=new KiComArtifactTransportBus($store,$inbox,$sources,$adapters,KiComArtifactRuntimeBridge::receiver());
            $result=$bus->handoffToUpdater($artifact,$allowAuto);
        }catch(Throwable $e){
            return ['ok'=>false,'code'=>'ARTIFACT_RUNTIME_FAILED'];
        }
        return $result+[
            'module'=>'artifact-transport',
            'discovery_code'=>(string)($discovery['code']??''),
            'discovery_sources'=>$discovery['evidence_sources']??[],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function sources(): array
    {
        $out=[];$ids=[];$priority=100;
        if(function_exists('kicomUpdateChannelsLoad')){
            try{$cfg=kicomUpdateChannelsLoad();}catch(Throwable $e){$cfg=[];}
            foreach(($cfg['pull']['feeds']??[]) as $feed){
                if(!is_array($feed))continue;$url=trim((string)($feed['url']??''));$p=parse_url($url);$host=is_array($p)?strtolower((string)($p['host']??'')):'';
                if($url===''||$host===''||filter_var($host,FILTER_VALIDATE_IP))continue;
                $base=self::sourceId((string)($feed['name']??'feed'))??'feed';$id=$base;$n=2;while(isset($ids[$id])){$id=$base.'-'.$n;$n++;}$ids[$id]=true;
                $out[]=['id'=>$id,'type'=>'https-channel','priority'=>$priority,'enabled'=>array_key_exists('enabled',$feed)?(bool)$feed['enabled']:true,'url'=>$url,'allowed_hosts'=>[$host],'package_hosts'=>[$host]];$priority+=20;
            }
        }

        $extra=self::readExtraConfig();
        foreach(($extra['sources']??[]) as $source){
            if(!is_array($source))continue;$id=self::sourceId((string)($source['id']??''));if($id===null||isset($ids[$id]))continue;$ids[$id]=true;$out[]=$source+['id'=>$id];
        }

        if(!isset($ids['local-inbox'])){
            $out[]=['id'=>'local-inbox','type'=>'local-inbox','priority'=>900,'enabled'=>true];$ids['local-inbox']=true;
        }
        if(!isset($ids['manual-upload'])){
            $out[]=['id'=>'manual-upload','type'=>'manual-upload','priority'=>980,'enabled'=>true];$ids['manual-upload']=true;
        }
        if(!isset($ids['recovery'])) $out[]=['id'=>'recovery','type'=>'recovery','priority'=>990,'enabled'=>true];
        return $out;
    }

    /** @return array<string,callable> */
    private static function adapters(): array
    {
        $extra=self::readExtraConfig();$adapters=[];
        $hasFtp=false;foreach(($extra['sources']??[]) as $s)if(is_array($s)&&($s['type']??'')==='external-adapter'&&($s['adapter']??'')==='ftp'){$hasFtp=true;break;}
        if($hasFtp&&function_exists('kicomArtifactFtpCredentials')){
            $provider=static function(array $source): array {
                try{$r=kicomArtifactFtpCredentials((string)($source['id']??''));}catch(Throwable $e){return [];}
                return is_array($r)?$r:[];
            };
            $adapters['ftp']=new KiComArtifactFtpAdapter($provider);
        }
        return $adapters;
    }

    /** @return array<string,mixed> */
    private static function offlineDescriptor(): array
    {
        if(!function_exists('kicomVarDir'))return ['ok'=>false,'code'=>'OFFLINE_KICOM_VAR_MISSING'];
        $file=rtrim(kicomVarDir(),'/').'/artifact_transport/inbox/artifact.json';
        if(!is_file($file))return ['ok'=>false,'code'=>'OFFLINE_DESCRIPTOR_NOT_FOUND'];
        $raw=@file_get_contents($file);if($raw===false||strlen($raw)>32768)return ['ok'=>false,'code'=>'OFFLINE_DESCRIPTOR_READ_FAILED'];
        try{$j=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){return ['ok'=>false,'code'=>'OFFLINE_DESCRIPTOR_INVALID'];}
        if(!is_array($j))return ['ok'=>false,'code'=>'OFFLINE_DESCRIPTOR_INVALID'];
        $version=trim((string)($j['version']??''));$filename=basename(trim((string)($j['filename']??'')));$sha=strtolower(trim((string)($j['sha256']??'')));
        if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$version)||!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+\-]{0,159}\.zip$/i',$filename)||!preg_match('/^[a-f0-9]{64}$/',$sha))return ['ok'=>false,'code'=>'OFFLINE_DESCRIPTOR_INVALID'];
        return ['ok'=>true,'artifact'=>['version'=>$version,'filename'=>$filename,'sha256'=>$sha]];
    }

    /** @return array<string,mixed> */
    private static function readExtraConfig(): array
    {
        if(!function_exists('kicomVarDir'))return [];$file=rtrim(kicomVarDir(),'/').'/artifact_transport/sources.json';if(!is_file($file))return [];
        $raw=@file_get_contents($file);if($raw===false||strlen($raw)>131072)return [];
        try{$j=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){return [];}
        return is_array($j)?$j:[];
    }

    private static function sourceId(string $value): ?string
    {
        $value=strtolower(trim($value));$value=preg_replace('/[^a-z0-9._-]+/','-',$value)??'';$value=trim($value,'-.');
        return $value!==''&&strlen($value)<=64?$value:null;
    }
}

/** @return array<string,mixed> */
function kicomArtifactTransportPullFallback(bool $allowAuto=true): array
{
    return KiComArtifactRuntimeModule::fallbackPull($allowAuto);
}
