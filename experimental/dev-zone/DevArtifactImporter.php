<?php
declare(strict_types=1);

/**
 * SHA-bound remote artifact importer for the KiCom DEV zone.
 *
 * This is deliberately not arbitrary remote fetch. It accepts HTTPS URLs only
 * from a small source allowlist, requires an expected SHA-256 before bytes are
 * trusted, stages and validates every file, and can write only below the
 * existing /dev directory. Replaced files are archived before install; rollback
 * archives newly introduced files instead of hard-deleting retained evidence.
 */
final class KiComDevArtifactImporter
{
    private const MAX_DOWNLOAD_BYTES = 16777216; // 16 MiB
    private const MAX_TOTAL_BYTES = 12582912;    // 12 MiB uncompressed payload
    private const MAX_FILE_BYTES = 2097152;      // 2 MiB per file
    private const MAX_FILES = 160;
    private const MAX_REDIRECTS = 4;

    /** @var list<string> */
    private const EXACT_HOSTS = [
        'raw.githubusercontent.com',
        'github.com',
        'objects.githubusercontent.com',
    ];
    /** @var list<string> */
    private const HOST_SUFFIXES = [
        '.githubusercontent.com',
        '.oaiusercontent.com',
    ];

    private string $devRoot;
    private string $store;

    public function __construct(string $devRoot,string $storageDir)
    {
        $real=realpath($devRoot);
        if(!is_string($real)||!is_dir($real)) throw new RuntimeException('DEV_ARTIFACT_ROOT_INVALID');
        $this->devRoot=rtrim($real,'/');
        $this->store=rtrim($storageDir,'/');
        if(!$this->ensureStore()) throw new RuntimeException('DEV_ARTIFACT_STORAGE_UNAVAILABLE');
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        return [
            'ok'=>true,
            'code'=>'DEV_ARTIFACT_READY',
            'scope'=>'dev-only',
            'https_only'=>true,
            'sha256_required'=>true,
            'max_download_bytes'=>self::MAX_DOWNLOAD_BYTES,
            'max_files'=>self::MAX_FILES,
            'allowed_hosts'=>array_merge(self::EXACT_HOSTS,['*.githubusercontent.com','*.oaiusercontent.com']),
            'formats'=>['kicom-dev-bundle-json','zip-flat-or-dev-prefix'],
            'hard_delete_existing'=>false,
        ];
    }

    /** @return array<string,mixed> */
    public function verifyRemote(string $url,string $expectedSha,string $format='auto'): array
    {
        $download=$this->download($url,$expectedSha);
        if(empty($download['ok'])) return $download;
        try {
            $inspect=$this->inspectFile((string)$download['_file'],$format);
            if(empty($inspect['ok'])) return $inspect;
            return [
                'ok'=>true,
                'code'=>'DEV_ARTIFACT_VERIFIED',
                'sha256'=>$download['sha256'],
                'bytes'=>$download['bytes'],
                'source_host'=>$download['source_host'],
                'format'=>$inspect['format'],
                'files'=>$inspect['files'],
                'total_bytes'=>$inspect['total_bytes'],
            ];
        } finally {
            $this->archiveDownloaded((string)($download['_file']??''),'verified-only',(string)($download['sha256']??''));
        }
    }

    /** @return array<string,mixed> */
    public function installRemote(string $url,string $expectedSha,string $format='auto'): array
    {
        $download=$this->download($url,$expectedSha);
        if(empty($download['ok'])) return $download;
        $file=(string)$download['_file'];
        $sha=(string)$download['sha256'];
        $sourceHost=(string)$download['source_host'];
        $result=$this->installVerifiedFile($file,$sha,$format,$url);
        if(!empty($result['ok'])) {
            $result['source_host']=$sourceHost;
            return $result;
        }
        $this->archiveDownloaded($file,'install-failed',$sha);
        return $result;
    }

    /**
     * CLI/selftest entry: install already obtained bytes through exactly the
     * same parser/stager without granting any network authority.
     * @return array<string,mixed>
     */
    public function installVerifiedBytes(string $bytes,string $expectedSha,string $format='auto',string $label='selftest'): array
    {
        $expectedSha=strtolower(trim($expectedSha));
        if(!preg_match('/^[a-f0-9]{64}$/',$expectedSha)||!hash_equals($expectedSha,hash('sha256',$bytes))) {
            return ['ok'=>false,'code'=>'DEV_ARTIFACT_SHA_MISMATCH'];
        }
        if(strlen($bytes)>self::MAX_DOWNLOAD_BYTES) return ['ok'=>false,'code'=>'DEV_ARTIFACT_TOO_LARGE'];
        $id='local-'.substr($expectedSha,0,16).'-'.bin2hex(random_bytes(3));
        $file=$this->store.'/incoming/'.$id.'.bin';
        if(@file_put_contents($file,$bytes,LOCK_EX)===false) return ['ok'=>false,'code'=>'DEV_ARTIFACT_STAGE_WRITE_FAILED'];
        @chmod($file,0600);
        return $this->installVerifiedFile($file,$expectedSha,$format,$label);
    }

    /** @return array<string,mixed> */
    private function installVerifiedFile(string $file,string $sha,string $format,string $source): array
    {
        $inspect=$this->inspectFile($file,$format,true);
        if(empty($inspect['ok'])) return $inspect;
        $entries=is_array($inspect['_entries']??null)?$inspect['_entries']:[];
        $artifactId=gmdate('Ymd\THis\Z').'-'.substr($sha,0,16).'-'.bin2hex(random_bytes(3));
        $stageRoot=$this->store.'/staging/'.$artifactId;
        $archiveRoot=$this->store.'/archive/'.$artifactId;
        if(!$this->mkdir($stageRoot)||!$this->mkdir($archiveRoot.'/before')||!$this->mkdir($archiveRoot.'/rollback-new')) {
            return ['ok'=>false,'code'=>'DEV_ARTIFACT_STAGE_CREATE_FAILED'];
        }

        $changes=[];$total=0;
        foreach($entries as $entry){
            if(!is_array($entry)) return ['ok'=>false,'code'=>'DEV_ARTIFACT_ENTRY_INVALID'];
            $path=$this->safePath((string)($entry['path']??''));
            $content=$entry['content']??null;
            if($path===null||!is_string($content)) return ['ok'=>false,'code'=>'DEV_ARTIFACT_ENTRY_INVALID'];
            $total+=strlen($content);
            if($total>self::MAX_TOTAL_BYTES) return ['ok'=>false,'code'=>'DEV_ARTIFACT_UNPACKED_TOO_LARGE'];
            $validation=$this->validateContent($path,$content);
            if(empty($validation['ok'])) return $validation+['path'=>$path];
            $stage=$stageRoot.'/'.$path;
            if(!$this->mkdir(dirname($stage))||@file_put_contents($stage,$content,LOCK_EX)===false) return ['ok'=>false,'code'=>'DEV_ARTIFACT_STAGE_WRITE_FAILED','path'=>$path];
            @chmod($stage,0600);
            $target=$this->devRoot.'/'.$path;
            $had=is_file($target)&&!is_link($target);
            if(is_link($target)||is_dir($target)) return ['ok'=>false,'code'=>'DEV_ARTIFACT_TARGET_TYPE_FORBIDDEN','path'=>$path];
            if($had){
                $before=$archiveRoot.'/before/'.$path;
                if(!$this->mkdir(dirname($before))||!@copy($target,$before)) return ['ok'=>false,'code'=>'DEV_ARTIFACT_ARCHIVE_FAILED','path'=>$path];
                @chmod($before,0600);
            }
            $changes[]=['path'=>$path,'had_previous'=>$had,'before_sha256'=>$had?(hash_file('sha256',$target)?:null):null,'after_sha256'=>hash('sha256',$content)];
        }

        $applied=[];
        foreach($changes as $change){
            $path=(string)$change['path'];
            $stage=$stageRoot.'/'.$path;$target=$this->devRoot.'/'.$path;
            if(!$this->mkdir(dirname($target))){$this->rollback($applied,$archiveRoot);return ['ok'=>false,'code'=>'DEV_ARTIFACT_TARGET_DIR_FAILED','path'=>$path];}
            $tmp=$target.'.kicom-artifact-'.bin2hex(random_bytes(4));
            if(!@copy($stage,$tmp)){@unlink($tmp);$this->rollback($applied,$archiveRoot);return ['ok'=>false,'code'=>'DEV_ARTIFACT_TARGET_WRITE_FAILED','path'=>$path];}
            @chmod($tmp,0644);
            if(!@rename($tmp,$target)){@unlink($tmp);$this->rollback($applied,$archiveRoot);return ['ok'=>false,'code'=>'DEV_ARTIFACT_TARGET_RENAME_FAILED','path'=>$path];}
            $applied[]=$change;
        }

        $receipt=[
            'schema'=>1,'artifact_id'=>$artifactId,'installed_at'=>gmdate('c'),'source'=>$this->sanitizeSource($source),
            'sha256'=>$sha,'format'=>$inspect['format'],'files'=>count($changes),'total_bytes'=>$total,'changes'=>$changes,
            'policy'=>['scope'=>'dev-only','existing_files_archived'=>true,'hard_delete_existing'=>false],
        ];
        $json=json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(is_string($json)) @file_put_contents($archiveRoot.'/receipt.json',$json."\n",LOCK_EX);
        if(is_file($file)) @rename($file,$archiveRoot.'/artifact.bin');
        if(is_dir($stageRoot)) @rename($stageRoot,$archiveRoot.'/installed-source');
        return [
            'ok'=>true,'code'=>'DEV_ARTIFACT_INSTALLED','artifact_id'=>$artifactId,'sha256'=>$sha,
            'format'=>$inspect['format'],'files'=>count($changes),'total_bytes'=>$total,'archive_retained'=>true,
        ];
    }

    /** @param list<array<string,mixed>> $applied */
    private function rollback(array $applied,string $archiveRoot): void
    {
        foreach(array_reverse($applied) as $change){
            $path=(string)($change['path']??'');if($path==='')continue;
            $target=$this->devRoot.'/'.$path;
            if(!empty($change['had_previous'])){
                $before=$archiveRoot.'/before/'.$path;
                if(is_file($before)){
                    $tmp=$target.'.rollback-'.bin2hex(random_bytes(3));
                    if(@copy($before,$tmp)){@chmod($tmp,0644);@rename($tmp,$target);}else{@unlink($tmp);}
                }
            } elseif(is_file($target)) {
                $dest=$archiveRoot.'/rollback-new/'.$path;
                if($this->mkdir(dirname($dest))) @rename($target,$dest);
            }
        }
    }

    /** @return array<string,mixed> */
    private function download(string $url,string $expectedSha): array
    {
        $expectedSha=strtolower(trim($expectedSha));
        if(!preg_match('/^[a-f0-9]{64}$/',$expectedSha)) return ['ok'=>false,'code'=>'DEV_ARTIFACT_SHA_REQUIRED'];
        $current=trim($url);
        for($redirect=0;$redirect<=self::MAX_REDIRECTS;$redirect++){
            $valid=$this->validateUrl($current);
            if(empty($valid['ok'])) return $valid;
            $ctx=stream_context_create(['http'=>[
                'method'=>'GET','follow_location'=>0,'ignore_errors'=>true,'timeout'=>25,
                'user_agent'=>'KiCom-DEV-Artifact/1.0','header'=>"Accept: application/octet-stream, application/json\r\nConnection: close\r\n",
            ],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
            $fh=@fopen($current,'rb',false,$ctx);
            if($fh===false) return ['ok'=>false,'code'=>'DEV_ARTIFACT_DOWNLOAD_OPEN_FAILED'];
            $meta=stream_get_meta_data($fh);$headers=is_array($meta['wrapper_data']??null)?$meta['wrapper_data']:[];
            $status=$this->httpStatus($headers);
            if($status>=300&&$status<400){
                $location=$this->headerValue($headers,'location');fclose($fh);
                if($location===null) return ['ok'=>false,'code'=>'DEV_ARTIFACT_REDIRECT_INVALID'];
                $current=$this->resolveRedirect($current,$location);
                if($current==='') return ['ok'=>false,'code'=>'DEV_ARTIFACT_REDIRECT_INVALID'];
                continue;
            }
            if($status!==200){fclose($fh);return ['ok'=>false,'code'=>'DEV_ARTIFACT_HTTP_STATUS','http_status'=>$status];}
            $tmp=$this->store.'/incoming/download-'.bin2hex(random_bytes(8)).'.bin';
            $out=@fopen($tmp,'wb');if($out===false){fclose($fh);return ['ok'=>false,'code'=>'DEV_ARTIFACT_STAGE_OPEN_FAILED'];}
            $hash=hash_init('sha256');$bytes=0;$ok=true;
            while(!feof($fh)){
                $chunk=fread($fh,65536);if($chunk===false){$ok=false;break;}if($chunk==='')continue;
                $bytes+=strlen($chunk);if($bytes>self::MAX_DOWNLOAD_BYTES){$ok=false;break;}
                hash_update($hash,$chunk);if(fwrite($out,$chunk)!==strlen($chunk)){$ok=false;break;}
            }
            fclose($fh);fclose($out);@chmod($tmp,0600);
            if(!$ok){@unlink($tmp);return ['ok'=>false,'code'=>$bytes>self::MAX_DOWNLOAD_BYTES?'DEV_ARTIFACT_TOO_LARGE':'DEV_ARTIFACT_DOWNLOAD_FAILED'];}
            $actual=hash_final($hash);
            if(!hash_equals($expectedSha,$actual)){ $this->archiveDownloaded($tmp,'sha-mismatch',$actual); return ['ok'=>false,'code'=>'DEV_ARTIFACT_SHA_MISMATCH','actual_sha256'=>$actual,'bytes'=>$bytes]; }
            return ['ok'=>true,'code'=>'DEV_ARTIFACT_DOWNLOADED','sha256'=>$actual,'bytes'=>$bytes,'source_host'=>(string)(parse_url($current,PHP_URL_HOST)?:''),'_file'=>$tmp];
        }
        return ['ok'=>false,'code'=>'DEV_ARTIFACT_TOO_MANY_REDIRECTS'];
    }

    /** @return array<string,mixed> */
    private function inspectFile(string $file,string $format,bool $includeContent=false): array
    {
        if(!is_file($file)) return ['ok'=>false,'code'=>'DEV_ARTIFACT_FILE_MISSING'];
        $head=@file_get_contents($file,false,null,0,4);if(!is_string($head))return ['ok'=>false,'code'=>'DEV_ARTIFACT_READ_FAILED'];
        $format=strtolower(trim($format));if($format===''||$format==='auto')$format=str_starts_with($head,'PK')?'zip':'bundle';
        if($format==='bundle'||$format==='json') return $this->inspectBundle($file,$includeContent);
        if($format==='zip') return $this->inspectZip($file,$includeContent);
        return ['ok'=>false,'code'=>'DEV_ARTIFACT_FORMAT_UNSUPPORTED'];
    }

    /** @return array<string,mixed> */
    private function inspectBundle(string $file,bool $includeContent): array
    {
        $raw=@file_get_contents($file);if(!is_string($raw))return ['ok'=>false,'code'=>'DEV_ARTIFACT_READ_FAILED'];
        try{$j=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){return ['ok'=>false,'code'=>'DEV_ARTIFACT_BUNDLE_JSON_INVALID'];}
        if(!is_array($j)||($j['schema']??0)!==1||($j['type']??'')!=='kicom-dev-bundle'||($j['target']??'')!=='dev') return ['ok'=>false,'code'=>'DEV_ARTIFACT_BUNDLE_SCHEMA_INVALID'];
        $files=is_array($j['files']??null)?$j['files']:[];if(count($files)<1||count($files)>self::MAX_FILES)return ['ok'=>false,'code'=>'DEV_ARTIFACT_FILE_COUNT_INVALID'];
        $entries=[];$seen=[];$total=0;
        foreach($files as $row){
            if(!is_array($row))return ['ok'=>false,'code'=>'DEV_ARTIFACT_ENTRY_INVALID'];
            $path=$this->safePath((string)($row['path']??''));if($path===null||isset($seen[$path]))return ['ok'=>false,'code'=>'DEV_ARTIFACT_PATH_INVALID'];$seen[$path]=true;
            $encoded=(string)($row['content_b64']??'');$content=base64_decode($encoded,true);if(!is_string($content))return ['ok'=>false,'code'=>'DEV_ARTIFACT_CONTENT_ENCODING_INVALID','path'=>$path];
            if(strlen($content)>self::MAX_FILE_BYTES)return ['ok'=>false,'code'=>'DEV_ARTIFACT_FILE_TOO_LARGE','path'=>$path];
            $sha=strtolower((string)($row['sha256']??''));if(!preg_match('/^[a-f0-9]{64}$/',$sha)||!hash_equals($sha,hash('sha256',$content)))return ['ok'=>false,'code'=>'DEV_ARTIFACT_FILE_SHA_MISMATCH','path'=>$path];
            $v=$this->validateContent($path,$content);if(empty($v['ok']))return $v+['path'=>$path];
            $total+=strlen($content);if($total>self::MAX_TOTAL_BYTES)return ['ok'=>false,'code'=>'DEV_ARTIFACT_UNPACKED_TOO_LARGE'];
            $entries[]=$includeContent?['path'=>$path,'content'=>$content]:['path'=>$path,'bytes'=>strlen($content),'sha256'=>$sha];
        }
        return ['ok'=>true,'code'=>'DEV_ARTIFACT_BUNDLE_VALID','format'=>'kicom-dev-bundle-json','files'=>count($entries),'total_bytes'=>$total,'_entries'=>$entries];
    }

    /** @return array<string,mixed> */
    private function inspectZip(string $file,bool $includeContent): array
    {
        if(!class_exists('ZipArchive'))return ['ok'=>false,'code'=>'DEV_ARTIFACT_ZIP_UNAVAILABLE'];
        $z=new ZipArchive();if($z->open($file)!==true)return ['ok'=>false,'code'=>'DEV_ARTIFACT_ZIP_OPEN_FAILED'];
        try{
            if($z->numFiles<1||$z->numFiles>self::MAX_FILES+40)return ['ok'=>false,'code'=>'DEV_ARTIFACT_FILE_COUNT_INVALID'];
            $raw=[];$prefix=true;
            for($i=0;$i<$z->numFiles;$i++){
                $stat=$z->statIndex($i);if(!is_array($stat))return ['ok'=>false,'code'=>'DEV_ARTIFACT_ZIP_STAT_FAILED'];
                $name=(string)($stat['name']??'');if($name===''||str_ends_with($name,'/'))continue;
                if(!str_starts_with($name,'dev/'))$prefix=false;
                $raw[]=['index'=>$i,'name'=>$name,'size'=>(int)($stat['size']??0)];
            }
            if(count($raw)<1||count($raw)>self::MAX_FILES)return ['ok'=>false,'code'=>'DEV_ARTIFACT_FILE_COUNT_INVALID'];
            $entries=[];$seen=[];$total=0;
            foreach($raw as $row){
                $name=(string)$row['name'];if($prefix)$name=substr($name,4);
                $path=$this->safePath($name);if($path===null||isset($seen[$path]))return ['ok'=>false,'code'=>'DEV_ARTIFACT_PATH_INVALID','path'=>$name];$seen[$path]=true;
                $size=(int)$row['size'];if($size<0||$size>self::MAX_FILE_BYTES)return ['ok'=>false,'code'=>'DEV_ARTIFACT_FILE_TOO_LARGE','path'=>$path];
                $ops=0;$attr=0;if($z->getExternalAttributesIndex((int)$row['index'],$ops,$attr)){ $mode=($attr>>16)&0170000;if($mode===0120000)return ['ok'=>false,'code'=>'DEV_ARTIFACT_SYMLINK_FORBIDDEN','path'=>$path]; }
                $content=$z->getFromIndex((int)$row['index']);if(!is_string($content)||strlen($content)!==$size)return ['ok'=>false,'code'=>'DEV_ARTIFACT_ZIP_READ_FAILED','path'=>$path];
                $v=$this->validateContent($path,$content);if(empty($v['ok']))return $v+['path'=>$path];
                $total+=$size;if($total>self::MAX_TOTAL_BYTES)return ['ok'=>false,'code'=>'DEV_ARTIFACT_UNPACKED_TOO_LARGE'];
                $entries[]=$includeContent?['path'=>$path,'content'=>$content]:['path'=>$path,'bytes'=>$size,'sha256'=>hash('sha256',$content)];
            }
            return ['ok'=>true,'code'=>'DEV_ARTIFACT_ZIP_VALID','format'=>'zip-flat-or-dev-prefix','files'=>count($entries),'total_bytes'=>$total,'_entries'=>$entries];
        } finally {$z->close();}
    }

    /** @return array<string,mixed> */
    private function validateContent(string $path,string $content): array
    {
        $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$base=basename($path);
        $allowed=in_array($ext,['php','js','html','json','md','txt','css'],true)||$base==='.htaccess';
        if(!$allowed)return ['ok'=>false,'code'=>'DEV_ARTIFACT_EXTENSION_FORBIDDEN'];
        if($ext==='php'){
            try{token_get_all($content,TOKEN_PARSE);}catch(ParseError $e){return ['ok'=>false,'code'=>'DEV_ARTIFACT_PHP_SYNTAX_INVALID'];}
        } elseif($ext==='json') {
            try{json_decode($content,true,128,JSON_THROW_ON_ERROR);}catch(Throwable $e){return ['ok'=>false,'code'=>'DEV_ARTIFACT_JSON_INVALID'];}
        } elseif($base==='.htaccess') {
            if(strlen($content)>4096||preg_match('/\b(SetHandler|AddHandler|auto_prepend_file|auto_append_file)\b/i',$content))return ['ok'=>false,'code'=>'DEV_ARTIFACT_HTACCESS_FORBIDDEN'];
        }
        return ['ok'=>true,'code'=>'DEV_ARTIFACT_CONTENT_VALID'];
    }

    private function safePath(string $path): ?string
    {
        $path=trim($path);if($path===''||strlen($path)>220||str_contains($path,"\0")||str_contains($path,'\\')||str_starts_with($path,'/')||preg_match('/^[A-Za-z]:/',$path))return null;
        $parts=explode('/',$path);foreach($parts as $part){if($part===''||$part==='.'||$part==='..'||strlen($part)>120)return null;}
        if(str_starts_with($path,'var/')||str_starts_with($path,'.git/'))return null;
        return $path;
    }

    /** @return array<string,mixed> */
    private function validateUrl(string $url): array
    {
        if(strlen($url)<8||strlen($url)>4096)return ['ok'=>false,'code'=>'DEV_ARTIFACT_URL_INVALID'];
        $p=parse_url($url);if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass']))return ['ok'=>false,'code'=>'DEV_ARTIFACT_HTTPS_REQUIRED'];
        if(isset($p['port'])&&(int)$p['port']!==443)return ['ok'=>false,'code'=>'DEV_ARTIFACT_PORT_FORBIDDEN'];
        $host=strtolower(rtrim((string)$p['host'],'.'));if(filter_var($host,FILTER_VALIDATE_IP))return ['ok'=>false,'code'=>'DEV_ARTIFACT_IP_LITERAL_FORBIDDEN'];
        if(!$this->hostAllowed($host))return ['ok'=>false,'code'=>'DEV_ARTIFACT_HOST_FORBIDDEN','host'=>$host];
        return ['ok'=>true,'code'=>'DEV_ARTIFACT_URL_ALLOWED','host'=>$host];
    }

    private function hostAllowed(string $host): bool
    {
        if(in_array($host,self::EXACT_HOSTS,true))return true;
        foreach(self::HOST_SUFFIXES as $suffix)if(str_ends_with($host,$suffix)&&strlen($host)>strlen($suffix))return true;
        return false;
    }

    /** @param list<string> $headers */
    private function httpStatus(array $headers): int
    {
        $status=0;foreach($headers as $h)if(preg_match('#^HTTP/\S+\s+(\d{3})#i,(string)$h,$m))$status=(int)$m[1];return $status;
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
        $p=parse_url($base);if(!is_array($p)||empty($p['host']))return '';
        $origin='https://'.$p['host'].(isset($p['port'])?':'.(int)$p['port']:'');
        if(str_starts_with($location,'/'))return $origin.$location;
        $path=(string)($p['path']??'/');$dir=rtrim(str_replace('\\','/',dirname($path)),'/');
        return $origin.($dir===''?'':$dir).'/'.$location;
    }

    private function ensureStore(): bool
    {
        foreach([$this->store,$this->store.'/incoming',$this->store.'/staging',$this->store.'/archive'] as $dir)if(!$this->mkdir($dir))return false;
        $deny=$this->store.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Options -Indexes\nRequire all denied\n",LOCK_EX);
        return true;
    }

    private function mkdir(string $dir): bool
    {
        if(is_dir($dir))return true;if(!@mkdir($dir,0700,true)&&!is_dir($dir))return false;@chmod($dir,0700);return true;
    }

    private function archiveDownloaded(string $file,string $reason,string $sha): void
    {
        if($file===''||!is_file($file))return;$dir=$this->store.'/archive/'.gmdate('Ymd\THis\Z').'-'.$reason.'-'.substr(preg_replace('/[^a-f0-9]/','',strtolower($sha)),0,16).'-'.bin2hex(random_bytes(2));
        if($this->mkdir($dir))@rename($file,$dir.'/artifact.bin');
    }

    private function sanitizeSource(string $source): string
    {
        $p=parse_url($source);if(is_array($p)&&!empty($p['host']))return 'https://'.strtolower((string)$p['host']).(string)($p['path']??'');
        return substr(preg_replace('/[^A-Za-z0-9_.:-]/','_',trim($source))??'source',0,180);
    }
}
