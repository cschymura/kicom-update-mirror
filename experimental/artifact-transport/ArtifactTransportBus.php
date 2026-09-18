<?php
declare(strict_types=1);

/**
 * KiCom central artifact transport bus.
 *
 * Transport and installation are intentionally separate. This class may obtain
 * bytes only from explicitly configured sources, verifies the expected SHA-256,
 * retains source/attempt history append-only, and then optionally hands the
 * verified file to a supplied KiCom updater callback. It never writes the
 * production application tree itself.
 */
final class KiComArtifactTransportBus
{
    public const STATE_UNKNOWN='UNKNOWN';
    public const STATE_AVAILABLE='AVAILABLE';
    public const STATE_UNAVAILABLE='UNAVAILABLE';
    public const STATE_DEGRADED='DEGRADED';
    public const STATE_FORBIDDEN='FORBIDDEN';
    public const STATE_STALE='STALE';

    private const MAX_PACKAGE_BYTES=16777216;
    private const MAX_CHANNEL_BYTES=262144;
    private const MAX_REDIRECTS=5;
    private const SOURCE_TYPES=['https-channel','https-package','local-inbox','external-adapter','manual-upload','recovery'];

    private string $store;
    private string $inbox;
    /** @var list<array<string,mixed>> */
    private array $sources=[];
    /** @var array<string,Closure> */
    private array $adapters=[];
    private ?Closure $receiver=null;

    /**
     * @param list<array<string,mixed>> $sources
     * @param array<string,callable> $adapters
     */
    public function __construct(string $storageDir,string $inboxDir,array $sources,array $adapters=[],?callable $receiver=null)
    {
        $this->store=rtrim($storageDir,'/');
        $this->inbox=rtrim($inboxDir,'/');
        foreach($adapters as $name=>$adapter){
            $name=strtolower(trim((string)$name));
            if($this->safeId($name)!==null) $this->adapters[$name]=Closure::fromCallable($adapter);
        }
        $this->receiver=$receiver!==null?Closure::fromCallable($receiver):null;
        if(!$this->ensureStorage()) throw new RuntimeException('ARTIFACT_STORAGE_UNAVAILABLE');
        $this->sources=$this->normalizeSources($sources);
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $state=$this->readState();
        $rows=[];
        foreach($this->sources as $source){
            $id=(string)$source['id'];
            $remembered=is_array($state[$id]??null)?$state[$id]:[];
            $rows[]=[
                'id'=>$id,
                'type'=>$source['type'],
                'priority'=>$source['priority'],
                'enabled'=>$source['enabled'],
                'automatic'=>$this->isAutomatic($source),
                'state'=>(string)($remembered['state']??($source['enabled']?self::STATE_UNKNOWN:self::STATE_FORBIDDEN)),
                'last_code'=>(string)($remembered['last_code']??'never'),
                'last_success_at'=>(string)($remembered['last_success_at']??''),
                'last_failure_at'=>(string)($remembered['last_failure_at']??''),
                'last_latency_ms'=>(int)($remembered['last_latency_ms']??0),
                'success_count'=>(int)($remembered['success_count']??0),
                'failure_count'=>(int)($remembered['failure_count']??0),
            ];
        }
        return [
            'ok'=>true,
            'code'=>'ARTIFACT_TRANSPORT_READY',
            'authority'=>'transport-only',
            'production_tree_write'=>false,
            'sha256_required'=>true,
            'history'=>'append-only',
            'sources'=>$rows,
        ];
    }

    /**
     * Try every configured automatic source in priority order until the exact
     * artifact is obtained. Slow fallbacks remain eligible after fast failures.
     * @return array<string,mixed>
     */
    public function acquire(array $artifact): array
    {
        $artifact=$this->normalizeArtifact($artifact);
        if(empty($artifact['ok'])) return $artifact;
        $attempts=[];
        foreach($this->sources as $source){
            if(empty($source['enabled'])){
                $attempts[]=['source'=>$source['id'],'code'=>'SOURCE_DISABLED','state'=>self::STATE_FORBIDDEN];
                continue;
            }
            if(!$this->isAutomatic($source)){
                $attempts[]=['source'=>$source['id'],'code'=>'SOURCE_REQUEST_ONLY','state'=>self::STATE_AVAILABLE];
                continue;
            }
            $started=microtime(true);
            $result=$this->acquireFromSource($source,$artifact);
            $latency=max(0,(int)round((microtime(true)-$started)*1000));
            $this->rememberAttempt($source,$result,$latency);
            $attempts[]=[
                'source'=>$source['id'],
                'code'=>(string)($result['code']??'UNKNOWN'),
                'state'=>!empty($result['ok'])?self::STATE_AVAILABLE:self::STATE_UNAVAILABLE,
                'latency_ms'=>$latency,
            ];
            if(!empty($result['ok'])){
                return $result+['attempts'=>$attempts,'artifact'=>$this->publicArtifact($artifact)];
            }
        }
        return ['ok'=>false,'code'=>'ARTIFACT_ALL_AUTOMATIC_SOURCES_FAILED','attempts'=>$attempts,'artifact'=>$this->publicArtifact($artifact)];
    }

    /**
     * Acquire and hand verified bytes to the existing KiCom updater callback.
     * The callback decides staging/risk/commit/rollback policy.
     * @return array<string,mixed>
     */
    public function handoffToUpdater(array $artifact,bool $allowAuto=true): array
    {
        if(!$this->receiver instanceof Closure) return ['ok'=>false,'code'=>'ARTIFACT_UPDATER_RECEIVER_UNAVAILABLE'];
        $acquired=$this->acquire($artifact);
        if(empty($acquired['ok'])) return $acquired;
        $path=(string)($acquired['_path']??'');
        if(!$this->pathInside($path,$this->store.'/incoming')||!is_file($path)) return ['ok'=>false,'code'=>'ARTIFACT_INTERNAL_PATH_INVALID'];
        $actual=hash_file('sha256',$path)?:'';
        $expected=(string)($acquired['sha256']??'');
        if($actual===''||!hash_equals($expected,$actual)){
            $this->archiveIncoming($path,'pre-handoff-hash-failed',$acquired);
            return ['ok'=>false,'code'=>'ARTIFACT_PRE_HANDOFF_SHA_MISMATCH'];
        }
        try{
            $receiver=$this->receiver;
            $r=$receiver(
                $path,
                (string)($acquired['filename']??'artifact.zip'),
                'transport:'.(string)($acquired['source_id']??'unknown'),
                $allowAuto
            );
            if(!is_array($r)) $r=['ok'=>false,'code'=>'ARTIFACT_UPDATER_RESPONSE_INVALID'];
        }catch(Throwable $e){
            $r=['ok'=>false,'code'=>'ARTIFACT_UPDATER_EXCEPTION'];
        }
        $this->archiveIncoming($path,!empty($r['ok'])?'handoff-success':'handoff-failed',$acquired+['updater_code'=>(string)($r['code']??'UNKNOWN')]);
        return $r+[
            'transport_source'=>(string)($acquired['source_id']??''),
            'transport_sha256'=>$expected,
            'transport_attempts'=>$acquired['attempts']??[],
        ];
    }

    /** @return array<string,mixed> */
    private function acquireFromSource(array $source,array $artifact): array
    {
        return match((string)$source['type']){
            'https-channel'=>$this->fromHttpsChannel($source,$artifact),
            'https-package'=>$this->fromHttpsPackage($source,$artifact),
            'local-inbox'=>$this->fromLocalInbox($source,$artifact),
            'external-adapter'=>$this->fromExternalAdapter($source,$artifact),
            default=>['ok'=>false,'code'=>'SOURCE_NOT_AUTOMATIC'],
        };
    }

    /** @return array<string,mixed> */
    private function fromHttpsChannel(array $source,array $artifact): array
    {
        $channelUrl=(string)($source['url']??'');
        $allowed=$this->allowedHosts($source);
        $channel=$this->httpGetString($channelUrl,self::MAX_CHANNEL_BYTES,$allowed);
        if(empty($channel['ok'])) return $channel;
        try{$json=json_decode((string)$channel['body'],true,128,JSON_THROW_ON_ERROR);}catch(Throwable $e){return ['ok'=>false,'code'=>'CHANNEL_JSON_INVALID'];}
        if(!is_array($json)) return ['ok'=>false,'code'=>'CHANNEL_JSON_INVALID'];
        $release=$this->releaseFromChannel($json,$artifact);
        if(empty($release['ok'])) return $release;
        $packageUrl=(string)$release['url'];
        $packageHosts=$this->allowedHosts($source,'package_hosts');
        if(!$packageHosts) $packageHosts=$allowed;
        return $this->downloadPackage($packageUrl,$artifact,$packageHosts,(string)$source['id']);
    }

    /** @return array<string,mixed> */
    private function fromHttpsPackage(array $source,array $artifact): array
    {
        $template=(string)($source['url']??'');
        $url=str_replace(['{version}','{filename}'],[rawurlencode((string)$artifact['version']),rawurlencode((string)$artifact['filename'])],$template);
        return $this->downloadPackage($url,$artifact,$this->allowedHosts($source),(string)$source['id']);
    }

    /** @return array<string,mixed> */
    private function fromLocalInbox(array $source,array $artifact): array
    {
        $root=realpath($this->inbox);
        if(!is_string($root)||!is_dir($root)) return ['ok'=>false,'code'=>'LOCAL_INBOX_UNAVAILABLE'];
        $candidate=$root.'/'.(string)$artifact['filename'];
        $real=realpath($candidate);
        if(!is_string($real)||!is_file($real)||!$this->pathInside($real,$root)) return ['ok'=>false,'code'=>'LOCAL_ARTIFACT_NOT_FOUND'];
        if((int)@filesize($real)>self::MAX_PACKAGE_BYTES) return ['ok'=>false,'code'=>'ARTIFACT_TOO_LARGE'];
        $sha=hash_file('sha256',$real)?:'';
        if(!hash_equals((string)$artifact['sha256'],$sha)) return ['ok'=>false,'code'=>'ARTIFACT_SHA_MISMATCH'];
        return $this->copyVerifiedIntoIncoming($real,$artifact,(string)$source['id']);
    }

    /** @return array<string,mixed> */
    private function fromExternalAdapter(array $source,array $artifact): array
    {
        $name=strtolower(trim((string)($source['adapter']??'')));
        $adapter=$this->adapters[$name]??null;
        if(!$adapter instanceof Closure) return ['ok'=>false,'code'=>'EXTERNAL_ADAPTER_UNAVAILABLE'];
        $incoming=$this->store.'/incoming';
        try{$r=$adapter($source,$this->publicArtifact($artifact),$incoming);}catch(Throwable $e){return ['ok'=>false,'code'=>'EXTERNAL_ADAPTER_EXCEPTION'];}
        if(!is_array($r)||empty($r['ok'])) return is_array($r)?$r:['ok'=>false,'code'=>'EXTERNAL_ADAPTER_RESPONSE_INVALID'];
        $path=(string)($r['path']??'');
        if(!$this->pathInside($path,$incoming)||!is_file($path)) return ['ok'=>false,'code'=>'EXTERNAL_ADAPTER_PATH_INVALID'];
        if((int)@filesize($path)>self::MAX_PACKAGE_BYTES){$this->archiveIncoming($path,'external-too-large',$artifact);return ['ok'=>false,'code'=>'ARTIFACT_TOO_LARGE'];}
        $sha=hash_file('sha256',$path)?:'';
        if($sha===''||!hash_equals((string)$artifact['sha256'],$sha)){
            $this->archiveIncoming($path,'external-sha-mismatch',$artifact+['actual_sha256'=>$sha]);
            return ['ok'=>false,'code'=>'ARTIFACT_SHA_MISMATCH'];
        }
        return ['ok'=>true,'code'=>'ARTIFACT_ACQUIRED','source_id'=>$source['id'],'sha256'=>$sha,'bytes'=>(int)@filesize($path),'filename'=>$artifact['filename'],'_path'=>$path];
    }

    /** @return array<string,mixed> */
    private function releaseFromChannel(array $channel,array $artifact): array
    {
        $expectedSha=(string)$artifact['sha256'];
        $version=(string)$artifact['version'];
        if(is_array($channel['releases']??null)){
            foreach($channel['releases'] as $release){
                if(!is_array($release)||(string)($release['version']??'')!==$version) continue;
                $sha=strtolower((string)($release['sha256']??''));
                $url=(string)($release['url']??'');
                if(!hash_equals($expectedSha,$sha)) return ['ok'=>false,'code'=>'CHANNEL_SHA_CONFLICT'];
                if($url==='') return ['ok'=>false,'code'=>'CHANNEL_PACKAGE_URL_MISSING'];
                return ['ok'=>true,'url'=>$url];
            }
            return ['ok'=>false,'code'=>'CHANNEL_VERSION_NOT_FOUND'];
        }
        $stableVersion=(string)($channel['version']??'');
        $sha=strtolower((string)($channel['sha256']??''));
        $url=(string)($channel['download_url']??$channel['url']??'');
        if($stableVersion!==$version) return ['ok'=>false,'code'=>'CHANNEL_VERSION_NOT_FOUND'];
        if(!hash_equals($expectedSha,$sha)) return ['ok'=>false,'code'=>'CHANNEL_SHA_CONFLICT'];
        if($url==='') return ['ok'=>false,'code'=>'CHANNEL_PACKAGE_URL_MISSING'];
        return ['ok'=>true,'url'=>$url];
    }

    /** @return array<string,mixed> */
    private function downloadPackage(string $url,array $artifact,array $allowedHosts,string $sourceId): array
    {
        $expected=(string)$artifact['sha256'];
        $current=$url;
        for($redirect=0;$redirect<=self::MAX_REDIRECTS;$redirect++){
            $valid=$this->validateHttpsUrl($current,$allowedHosts);
            if(empty($valid['ok'])) return $valid;
            $ctx=$this->httpContext();
            $fh=@fopen($current,'rb',false,$ctx);
            if($fh===false) return ['ok'=>false,'code'=>'HTTP_OPEN_FAILED'];
            $meta=stream_get_meta_data($fh);
            $headers=is_array($meta['wrapper_data']??null)?$meta['wrapper_data']:[];
            $status=$this->httpStatus($headers);
            if($status>=300&&$status<400){
                $location=$this->headerValue($headers,'location');fclose($fh);
                if($location===null) return ['ok'=>false,'code'=>'HTTP_REDIRECT_INVALID'];
                $current=$this->resolveRedirect($current,$location);
                if($current==='') return ['ok'=>false,'code'=>'HTTP_REDIRECT_INVALID'];
                continue;
            }
            if($status!==200){fclose($fh);return ['ok'=>false,'code'=>'HTTP_STATUS','http_status'=>$status];}
            $dest=$this->store.'/incoming/'.bin2hex(random_bytes(8)).'-'.(string)$artifact['filename'];
            $out=@fopen($dest,'wb');if($out===false){fclose($fh);return ['ok'=>false,'code'=>'INCOMING_OPEN_FAILED'];}
            $hash=hash_init('sha256');$bytes=0;$ok=true;
            while(!feof($fh)){
                $chunk=fread($fh,65536);if($chunk===false){$ok=false;break;}if($chunk==='')continue;
                $bytes+=strlen($chunk);if($bytes>self::MAX_PACKAGE_BYTES){$ok=false;break;}
                hash_update($hash,$chunk);if(fwrite($out,$chunk)!==strlen($chunk)){$ok=false;break;}
            }
            fclose($fh);fclose($out);@chmod($dest,0600);
            if(!$ok){$this->archiveIncoming($dest,$bytes>self::MAX_PACKAGE_BYTES?'too-large':'download-failed',$artifact);return ['ok'=>false,'code'=>$bytes>self::MAX_PACKAGE_BYTES?'ARTIFACT_TOO_LARGE':'HTTP_DOWNLOAD_FAILED'];}
            $sha=hash_final($hash);
            if(!hash_equals($expected,$sha)){$this->archiveIncoming($dest,'sha-mismatch',$artifact+['actual_sha256'=>$sha]);return ['ok'=>false,'code'=>'ARTIFACT_SHA_MISMATCH'];}
            return ['ok'=>true,'code'=>'ARTIFACT_ACQUIRED','source_id'=>$sourceId,'sha256'=>$sha,'bytes'=>$bytes,'filename'=>$artifact['filename'],'_path'=>$dest];
        }
        return ['ok'=>false,'code'=>'HTTP_TOO_MANY_REDIRECTS'];
    }

    /** @return array<string,mixed> */
    private function httpGetString(string $url,int $maxBytes,array $allowedHosts): array
    {
        $current=$url;
        for($redirect=0;$redirect<=self::MAX_REDIRECTS;$redirect++){
            $valid=$this->validateHttpsUrl($current,$allowedHosts);if(empty($valid['ok']))return $valid;
            $ctx=$this->httpContext();$fh=@fopen($current,'rb',false,$ctx);if($fh===false)return ['ok'=>false,'code'=>'HTTP_OPEN_FAILED'];
            $meta=stream_get_meta_data($fh);$headers=is_array($meta['wrapper_data']??null)?$meta['wrapper_data']:[];$status=$this->httpStatus($headers);
            if($status>=300&&$status<400){$location=$this->headerValue($headers,'location');fclose($fh);if($location===null)return ['ok'=>false,'code'=>'HTTP_REDIRECT_INVALID'];$current=$this->resolveRedirect($current,$location);if($current==='')return ['ok'=>false,'code'=>'HTTP_REDIRECT_INVALID'];continue;}
            if($status!==200){fclose($fh);return ['ok'=>false,'code'=>'HTTP_STATUS','http_status'=>$status];}
            $body='';while(!feof($fh)){$chunk=fread($fh,32768);if($chunk===false){fclose($fh);return ['ok'=>false,'code'=>'HTTP_READ_FAILED'];}$body.=$chunk;if(strlen($body)>$maxBytes){fclose($fh);return ['ok'=>false,'code'=>'HTTP_BODY_TOO_LARGE'];}}fclose($fh);
            return ['ok'=>true,'code'=>'HTTP_OK','body'=>$body];
        }
        return ['ok'=>false,'code'=>'HTTP_TOO_MANY_REDIRECTS'];
    }

    private function httpContext()
    {
        return stream_context_create(['http'=>['method'=>'GET','follow_location'=>0,'ignore_errors'=>true,'timeout'=>30,'user_agent'=>'KiCom-Artifact-Transport/1.0','header'=>"Accept: application/json, application/octet-stream\r\nConnection: close\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
    }

    /** @return array<string,mixed> */
    private function copyVerifiedIntoIncoming(string $source,array $artifact,string $sourceId): array
    {
        $dest=$this->store.'/incoming/'.bin2hex(random_bytes(8)).'-'.(string)$artifact['filename'];
        if(!@copy($source,$dest)) return ['ok'=>false,'code'=>'INCOMING_COPY_FAILED'];
        @chmod($dest,0600);
        return ['ok'=>true,'code'=>'ARTIFACT_ACQUIRED','source_id'=>$sourceId,'sha256'=>$artifact['sha256'],'bytes'=>(int)@filesize($dest),'filename'=>$artifact['filename'],'_path'=>$dest];
    }

    /** @return array<string,mixed> */
    private function normalizeArtifact(array $artifact): array
    {
        $version=trim((string)($artifact['version']??''));$filename=basename(trim((string)($artifact['filename']??'')));$sha=strtolower(trim((string)($artifact['sha256']??'')));
        if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/',$version)) return ['ok'=>false,'code'=>'ARTIFACT_VERSION_INVALID'];
        if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,159}\.zip$/i',$filename)) return ['ok'=>false,'code'=>'ARTIFACT_FILENAME_INVALID'];
        if(!preg_match('/^[a-f0-9]{64}$/',$sha)) return ['ok'=>false,'code'=>'ARTIFACT_SHA_REQUIRED'];
        return ['ok'=>true,'version'=>$version,'filename'=>$filename,'sha256'=>$sha];
    }

    /** @return array<string,mixed> */
    private function publicArtifact(array $artifact): array
    {
        return ['version'=>(string)($artifact['version']??''),'filename'=>(string)($artifact['filename']??''),'sha256'=>(string)($artifact['sha256']??'')];
    }

    /** @param list<array<string,mixed>> $sources @return list<array<string,mixed>> */
    private function normalizeSources(array $sources): array
    {
        $out=[];$seen=[];
        foreach($sources as $source){
            if(!is_array($source))continue;$id=$this->safeId((string)($source['id']??''));$type=strtolower(trim((string)($source['type']??'')));
            if($id===null||isset($seen[$id])||!in_array($type,self::SOURCE_TYPES,true))continue;$seen[$id]=true;
            $source['id']=$id;$source['type']=$type;$source['enabled']=array_key_exists('enabled',$source)?(bool)$source['enabled']:true;$source['priority']=max(1,min(9999,(int)($source['priority']??500)));
            $out[]=$source;
        }
        usort($out,fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority'])?:strcmp((string)$a['id'],(string)$b['id']));
        return $out;
    }

    private function safeId(string $id): ?string
    {
        $id=strtolower(trim($id));return preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/',$id)?$id:null;
    }

    private function isAutomatic(array $source): bool
    {
        return in_array((string)$source['type'],['https-channel','https-package','local-inbox','external-adapter'],true);
    }

    /** @return list<string> */
    private function allowedHosts(array $source,string $field='allowed_hosts'): array
    {
        $rows=is_array($source[$field]??null)?$source[$field]:[];$out=[];
        foreach($rows as $host){$host=strtolower(rtrim(trim((string)$host),'.'));if(preg_match('/^[a-z0-9.-]+$/',$host)&&!filter_var($host,FILTER_VALIDATE_IP))$out[]=$host;}
        return array_values(array_unique($out));
    }

    /** @return array<string,mixed> */
    private function validateHttpsUrl(string $url,array $allowedHosts): array
    {
        if($url===''||strlen($url)>4096||!$allowedHosts)return ['ok'=>false,'code'=>'HTTPS_SOURCE_NOT_CONFIGURED'];
        $p=parse_url($url);if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass']))return ['ok'=>false,'code'=>'HTTPS_URL_INVALID'];
        if(isset($p['port'])&&(int)$p['port']!==443)return ['ok'=>false,'code'=>'HTTPS_PORT_FORBIDDEN'];
        $host=strtolower(rtrim((string)$p['host'],'.'));if(filter_var($host,FILTER_VALIDATE_IP)||!in_array($host,$allowedHosts,true))return ['ok'=>false,'code'=>'HTTPS_HOST_FORBIDDEN','host'=>$host];
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
        $location=trim($location);if($location==='')return '';if(preg_match('#^https://#i',$location))return $location;if(str_starts_with($location,'//'))return 'https:'.$location;
        $p=parse_url($base);if(!is_array($p)||empty($p['host']))return '';$port=isset($p['port'])?':'.(string)(int)$p['port']:'';$origin='https://'.(string)$p['host'].$port;
        if(str_starts_with($location,'/'))return $origin.$location;$path=(string)($p['path']??'/');$dir=rtrim(str_replace('\\','/',dirname($path)),'/');return $origin.($dir===''?'':$dir).'/'.$location;
    }

    private function ensureStorage(): bool
    {
        foreach([$this->store,$this->store.'/incoming',$this->store.'/archive',$this->store.'/state'] as $dir){if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return false;@chmod($dir,0700);}
        if(!is_dir($this->inbox))@mkdir($this->inbox,0700,true);
        $deny=$this->store.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Options -Indexes\nRequire all denied\n",LOCK_EX);
        return true;
    }

    /** @return array<string,array<string,mixed>> */
    private function readState(): array
    {
        $file=$this->store.'/state/current.json';if(!is_file($file))return [];$j=json_decode((string)@file_get_contents($file),true);return is_array($j)?$j:[];
    }

    private function rememberAttempt(array $source,array $result,int $latency): void
    {
        $id=(string)$source['id'];$state=$this->readState();$prev=is_array($state[$id]??null)?$state[$id]:[];$ok=!empty($result['ok']);$now=gmdate('c');
        $row=$prev+['source_id'=>$id,'type'=>$source['type'],'success_count'=>0,'failure_count'=>0];
        $row['state']=$ok?self::STATE_AVAILABLE:(($prev['state']??'')===self::STATE_AVAILABLE?self::STATE_DEGRADED:self::STATE_UNAVAILABLE);
        $row['last_code']=(string)($result['code']??'UNKNOWN');$row['last_attempt_at']=$now;$row['last_latency_ms']=$latency;
        if($ok){$row['success_count']=(int)($prev['success_count']??0)+1;$row['last_success_at']=$now;}else{$row['failure_count']=(int)($prev['failure_count']??0)+1;$row['last_failure_at']=$now;}
        $state[$id]=$row;$json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if(is_string($json))@file_put_contents($this->store.'/state/current.json',$json."\n",LOCK_EX);
        $history=json_encode(['at'=>$now,'source_id'=>$id,'type'=>$source['type'],'ok'=>$ok,'code'=>$row['last_code'],'state'=>$row['state'],'latency_ms'=>$latency],JSON_UNESCAPED_SLASHES);if(is_string($history))@file_put_contents($this->store.'/state/history.jsonl',$history."\n",FILE_APPEND|LOCK_EX);
    }

    private function archiveIncoming(string $path,string $reason,array $detail): void
    {
        if(!$this->pathInside($path,$this->store.'/incoming')||!is_file($path))return;$id=gmdate('Ymd\THis\Z').'-'.preg_replace('/[^a-z0-9-]/','-',strtolower($reason)).'-'.bin2hex(random_bytes(3));$dir=$this->store.'/archive/'.$id;
        if(!@mkdir($dir,0700,true)&&!is_dir($dir))return;@chmod($dir,0700);@rename($path,$dir.'/artifact.zip');
        $safe=['at'=>gmdate('c'),'reason'=>$reason,'source_id'=>(string)($detail['source_id']??''),'sha256'=>(string)($detail['sha256']??''),'filename'=>(string)($detail['filename']??''),'updater_code'=>(string)($detail['updater_code']??'')];$j=json_encode($safe,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if(is_string($j))@file_put_contents($dir.'/receipt.json',$j."\n",LOCK_EX);
    }

    private function pathInside(string $path,string $root): bool
    {
        $real=realpath($path);$base=realpath($root);if(!is_string($real)||!is_string($base))return false;$base=rtrim(str_replace('\\','/',$base),'/').'/';$real=str_replace('\\','/',$real);return str_starts_with($real,$base);
    }
}
