<?php
declare(strict_types=1);

/**
 * Read-only release discovery for KiCom artifact transport.
 *
 * This class never downloads or installs packages. It only reads explicitly
 * allowlisted HTTPS channel documents and resolves the newest exact
 * version+SHA-256 descriptor. Conflicting hashes for the same newest version
 * fail closed.
 */
final class KiComArtifactChannelResolver
{
    private const MAX_CHANNEL_BYTES=262144;
    private const MAX_REDIRECTS=4;

    /** @var list<array<string,mixed>> */
    private array $sources=[];
    private ?Closure $fetcher=null;

    /**
     * @param list<array<string,mixed>> $sources
     * @param null|callable(string,int,array):array<string,mixed> $fetcher test seam
     */
    public function __construct(array $sources,?callable $fetcher=null)
    {
        foreach($sources as $source){
            if(!is_array($source)) continue;
            $id=$this->safeId((string)($source['id']??''));
            $type=strtolower(trim((string)($source['type']??'')));
            if($id===null||$type!=='https-channel') continue;
            $source['id']=$id;
            $source['type']=$type;
            $source['enabled']=array_key_exists('enabled',$source)?(bool)$source['enabled']:true;
            $source['priority']=max(1,min(9999,(int)($source['priority']??500)));
            $this->sources[]=$source;
        }
        usort($this->sources,fn(array $a,array $b):int=>((int)$a['priority']<=>(int)$b['priority'])?:strcmp((string)$a['id'],(string)$b['id']));
        $this->fetcher=$fetcher!==null?Closure::fromCallable($fetcher):null;
    }

    /** @return array<string,mixed> */
    public function discoverLatest(string $currentVersion): array
    {
        $currentVersion=trim($currentVersion);
        if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$currentVersion)) return ['ok'=>false,'code'=>'DISCOVERY_CURRENT_VERSION_INVALID'];

        $checks=[];$candidates=[];
        foreach($this->sources as $source){
            $id=(string)$source['id'];
            if(empty($source['enabled'])){
                $checks[]=['source'=>$id,'ok'=>false,'code'=>'SOURCE_DISABLED'];
                continue;
            }
            $url=trim((string)($source['url']??''));
            $allowed=$this->allowedHosts($source,'allowed_hosts');
            $started=microtime(true);
            $response=$this->fetch($url,self::MAX_CHANNEL_BYTES,$allowed);
            $latency=max(0,(int)round((microtime(true)-$started)*1000));
            if(empty($response['ok'])){
                $checks[]=['source'=>$id,'ok'=>false,'code'=>(string)($response['code']??'CHANNEL_FETCH_FAILED'),'latency_ms'=>$latency];
                continue;
            }
            try{$channel=json_decode((string)($response['body']??''),true,128,JSON_THROW_ON_ERROR);}catch(Throwable $e){$channel=null;}
            if(!is_array($channel)){
                $checks[]=['source'=>$id,'ok'=>false,'code'=>'CHANNEL_JSON_INVALID','latency_ms'=>$latency];
                continue;
            }
            $rows=$this->channelReleases($channel);
            $accepted=0;
            foreach($rows as $release){
                $normalized=$this->normalizeRelease($release,$source);
                if(empty($normalized['ok'])) continue;
                $version=(string)$normalized['version'];
                if(version_compare($version,$currentVersion,'<=') ) continue;
                $candidates[]=$normalized+['source_id'=>$id,'priority'=>(int)$source['priority']];
                $accepted++;
            }
            $checks[]=['source'=>$id,'ok'=>true,'code'=>$accepted>0?'CHANNEL_RELEASES_FOUND':'CHANNEL_NO_NEWER_RELEASE','releases'=>$accepted,'latency_ms'=>$latency];
        }

        if(!$candidates){
            return ['ok'=>true,'code'=>'ARTIFACT_NO_UPDATE','current_version'=>$currentVersion,'checks'=>$checks];
        }

        usort($candidates,function(array $a,array $b): int {
            $v=version_compare((string)$b['version'],(string)$a['version']);
            return $v!==0?$v:(((int)$a['priority']<=>(int)$b['priority'])?:strcmp((string)$a['source_id'],(string)$b['source_id']));
        });
        $bestVersion=(string)$candidates[0]['version'];
        $same=array_values(array_filter($candidates,fn(array $row):bool=>(string)$row['version']===$bestVersion));
        $hashes=array_values(array_unique(array_map(fn(array $row):string=>(string)$row['sha256'],$same)));
        if(count($hashes)!==1){
            return ['ok'=>false,'code'=>'ARTIFACT_CHANNEL_HASH_CONFLICT','version'=>$bestVersion,'hashes'=>$hashes,'sources'=>array_values(array_unique(array_map(fn(array $row):string=>(string)$row['source_id'],$same))),'checks'=>$checks];
        }

        usort($same,fn(array $a,array $b):int=>((int)$a['priority']<=>(int)$b['priority'])?:strcmp((string)$a['source_id'],(string)$b['source_id']));
        $chosen=$same[0];
        return [
            'ok'=>true,
            'code'=>'ARTIFACT_RELEASE_DISCOVERED',
            'current_version'=>$currentVersion,
            'artifact'=>[
                'version'=>$bestVersion,
                'filename'=>(string)$chosen['filename'],
                'sha256'=>(string)$chosen['sha256'],
            ],
            'evidence_sources'=>array_values(array_unique(array_map(fn(array $row):string=>(string)$row['source_id'],$same))),
            'checks'=>$checks,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function channelReleases(array $channel): array
    {
        if(is_array($channel['releases']??null)) return array_values(array_filter($channel['releases'],'is_array'));
        if(isset($channel['version'])||isset($channel['sha256'])) return [$channel];
        return [];
    }

    /** @return array<string,mixed> */
    private function normalizeRelease(array $release,array $source): array
    {
        $version=trim((string)($release['version']??''));
        $sha=strtolower(trim((string)($release['sha256']??'')));
        $url=trim((string)($release['download_url']??$release['url']??''));
        if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$version)) return ['ok'=>false,'code'=>'RELEASE_VERSION_INVALID'];
        if(!preg_match('/^[a-f0-9]{64}$/',$sha)) return ['ok'=>false,'code'=>'RELEASE_SHA_INVALID'];
        $packageHosts=$this->allowedHosts($source,'package_hosts');
        if(!$packageHosts) $packageHosts=$this->allowedHosts($source,'allowed_hosts');
        $valid=$this->validateHttpsUrl($url,$packageHosts);
        if(empty($valid['ok'])) return $valid;
        $filename=basename(trim((string)($release['filename']??'')));
        if($filename===''||$filename==='.'){
            $p=parse_url($url);
            $filename=basename((string)($p['path']??''));
        }
        if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+\-]{0,159}\.zip$/i',$filename)) return ['ok'=>false,'code'=>'RELEASE_FILENAME_INVALID'];
        return ['ok'=>true,'version'=>$version,'sha256'=>$sha,'filename'=>$filename];
    }

    /** @return array<string,mixed> */
    private function fetch(string $url,int $maxBytes,array $allowedHosts): array
    {
        if($this->fetcher instanceof Closure){
            try{$r=($this->fetcher)($url,$maxBytes,$allowedHosts);}catch(Throwable $e){return ['ok'=>false,'code'=>'CHANNEL_FETCHER_EXCEPTION'];}
            return is_array($r)?$r:['ok'=>false,'code'=>'CHANNEL_FETCHER_INVALID'];
        }
        return $this->httpGetString($url,$maxBytes,$allowedHosts);
    }

    /** @return array<string,mixed> */
    private function httpGetString(string $url,int $maxBytes,array $allowedHosts): array
    {
        $current=$url;
        for($redirect=0;$redirect<=self::MAX_REDIRECTS;$redirect++){
            $valid=$this->validateHttpsUrl($current,$allowedHosts);if(empty($valid['ok']))return $valid;
            $ctx=stream_context_create(['http'=>['method'=>'GET','follow_location'=>0,'ignore_errors'=>true,'timeout'=>20,'user_agent'=>'KiCom-Artifact-Resolver/1.0','header'=>"Accept: application/json\r\nConnection: close\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
            $fh=@fopen($current,'rb',false,$ctx);if($fh===false)return ['ok'=>false,'code'=>'CHANNEL_HTTP_OPEN_FAILED'];
            $meta=stream_get_meta_data($fh);$headers=is_array($meta['wrapper_data']??null)?$meta['wrapper_data']:[];$status=$this->httpStatus($headers);
            if($status>=300&&$status<400){
                $location=$this->headerValue($headers,'location');fclose($fh);
                if($location===null)return ['ok'=>false,'code'=>'CHANNEL_REDIRECT_INVALID'];
                $current=$this->resolveRedirect($current,$location);if($current==='')return ['ok'=>false,'code'=>'CHANNEL_REDIRECT_INVALID'];
                continue;
            }
            if($status!==200){fclose($fh);return ['ok'=>false,'code'=>'CHANNEL_HTTP_STATUS','http_status'=>$status];}
            $body='';
            while(!feof($fh)){
                $chunk=fread($fh,32768);if($chunk===false){fclose($fh);return ['ok'=>false,'code'=>'CHANNEL_HTTP_READ_FAILED'];}
                $body.=$chunk;if(strlen($body)>$maxBytes){fclose($fh);return ['ok'=>false,'code'=>'CHANNEL_BODY_TOO_LARGE'];}
            }
            fclose($fh);return ['ok'=>true,'code'=>'CHANNEL_HTTP_OK','body'=>$body];
        }
        return ['ok'=>false,'code'=>'CHANNEL_TOO_MANY_REDIRECTS'];
    }

    /** @return list<string> */
    private function allowedHosts(array $source,string $field): array
    {
        $rows=is_array($source[$field]??null)?$source[$field]:[];$out=[];
        foreach($rows as $host){$host=strtolower(rtrim(trim((string)$host),'.'));if(preg_match('/^[a-z0-9.-]+$/',$host)&&!filter_var($host,FILTER_VALIDATE_IP))$out[]=$host;}
        return array_values(array_unique($out));
    }

    /** @return array<string,mixed> */
    private function validateHttpsUrl(string $url,array $allowedHosts): array
    {
        if($url===''||strlen($url)>4096||!$allowedHosts)return ['ok'=>false,'code'=>'CHANNEL_SOURCE_NOT_CONFIGURED'];
        $p=parse_url($url);if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass']))return ['ok'=>false,'code'=>'CHANNEL_URL_INVALID'];
        if(isset($p['port'])&&(int)$p['port']!==443)return ['ok'=>false,'code'=>'CHANNEL_PORT_FORBIDDEN'];
        $host=strtolower(rtrim((string)$p['host'],'.'));
        if(filter_var($host,FILTER_VALIDATE_IP)||!in_array($host,$allowedHosts,true))return ['ok'=>false,'code'=>'CHANNEL_HOST_FORBIDDEN','host'=>$host];
        return ['ok'=>true,'host'=>$host];
    }

    /** @param list<string> $headers */
    private function httpStatus(array $headers): int
    {
        $status=0;foreach($headers as $h)if(preg_match('#^HTTP/\S+\s+(\d{3})#i',(string)$h,$m))$status=(int)$m[1];return $status;
    }

    /** @param list<string> $headers */
    private function headerValue(array $headers,string $name): ?string
    {
        foreach(array_reverse($headers) as $h)if(stripos((string)$h,$name.':')===0)return trim(substr((string)$h,strlen($name)+1));return null;
    }

    private function resolveRedirect(string $base,string $location): string
    {
        $location=trim($location);if($location==='')return '';
        if(preg_match('#^https://#i',$location))return $location;
        if(str_starts_with($location,'//'))return 'https:'.$location;
        $p=parse_url($base);if(!is_array($p)||empty($p['host']))return '';$origin='https://'.(string)$p['host'];
        if(str_starts_with($location,'/'))return $origin.$location;
        $path=(string)($p['path']??'/');$dir=rtrim(str_replace('\\','/',dirname($path)),'/');return $origin.($dir===''?'':$dir).'/'.$location;
    }

    private function safeId(string $id): ?string
    {
        $id=strtolower(trim($id));return preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/',$id)?$id:null;
    }
}
