<?php
declare(strict_types=1);

/**
 * Sanitized DEV observability for troubleshooting without exposing secrets or
 * absolute hosting paths. Public doctor output is intentionally bounded.
 */
final class KiComDevObserver
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir=rtrim($dir,'/');
        if(!is_dir($this->dir)&&!@mkdir($this->dir,0700,true)&&!is_dir($this->dir)) throw new RuntimeException('DEV_OBSERVER_STORAGE_UNAVAILABLE');
        @chmod($this->dir,0700);
        $deny=$this->dir.'/.htaccess';
        if(!is_file($deny)) @file_put_contents($deny,"Options -Indexes\nRequire all denied\n",LOCK_EX);
    }

    public function begin(string $operation,array $detail=[]): string
    {
        $id='op-'.bin2hex(random_bytes(8));
        $this->append(['operation_id'=>$id,'event'=>'BEGIN','operation'=>$this->cleanCode($operation),'at'=>gmdate('c'),'detail'=>$this->sanitize($detail)]);
        return $id;
    }

    public function stage(string $id,string $stage,string $code='OK',array $detail=[]): void
    {
        $this->append(['operation_id'=>$this->cleanId($id),'event'=>'STAGE','stage'=>$this->cleanCode($stage),'code'=>$this->cleanCode($code),'at'=>gmdate('c'),'detail'=>$this->sanitize($detail)]);
    }

    public function finish(string $id,bool $ok,string $code,array $detail=[]): void
    {
        $this->append(['operation_id'=>$this->cleanId($id),'event'=>'FINISH','ok'=>$ok,'code'=>$this->cleanCode($code),'at'=>gmdate('c'),'detail'=>$this->sanitize($detail)]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit=40): array
    {
        $limit=max(1,min(100,$limit));
        $file=$this->dir.'/trace.jsonl';
        if(!is_file($file)) return [];
        $lines=@file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if(!is_array($lines)) return [];
        $out=[];
        foreach(array_slice($lines,-$limit) as $line){
            $j=json_decode((string)$line,true);
            if(is_array($j)) $out[]=$j;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function doctor(string $devDir): array
    {
        $devDir=rtrim($devDir,'/');
        $required=[
            'dev-api.php','dev-auth.php','dev-expansion.php','DevSession.php','DevExpansionBindings.php','DevSandboxPerception.php',
            'expansion/ExpansionService.php','expansion/ExpansionOrchestrator.php','expansion/ExpansionCellPackageBuilder.php',
            'expansion/ExpansionLocalFilesystemDeployer.php','expansion/ExpansionKiComDeployTargetResolver.php',
            'expansion/cell-runtime/common.php','expansion/cell-runtime/bootstrap.php','expansion/cell-runtime/federation.php','expansion/cell-runtime/status.php',
        ];
        $files=[];$missing=[];
        foreach($required as $rel){
            $p=$devDir.'/'.$rel;
            $exists=is_file($p);
            if(!$exists) $missing[]=$rel;
            $files[]=['path'=>$rel,'exists'=>$exists,'bytes'=>$exists?(@filesize($p)?:0):0,'sha256'=>$exists?(@hash_file('sha256',$p)?:null):null];
        }

        $sandbox=['available'=>false,'class'=>null,'writable'=>false,'healthcheck'=>false];
        if(function_exists('kicomDeployTarget')){
            try{
                $t=kicomDeployTarget('sandbox',true);
                if(is_array($t)){
                    $class=strtolower(trim((string)($t['class']??'')));
                    $root=(string)($t['root']??'');
                    $sandbox=[
                        'available'=>in_array($class,['test','staging'],true),
                        'class'=>$class?:null,
                        'writable'=>$root!==''&&is_dir($root)&&is_writable($root),
                        'healthcheck'=>trim((string)($t['health_url']??''))!=='',
                    ];
                }
            }catch(Throwable $e){
                $sandbox=['available'=>false,'class'=>null,'writable'=>false,'healthcheck'=>false,'code'=>'SANDBOX_RESOLVER_FAILED'];
            }
        }

        return [
            'ok'=>count($missing)===0,
            'code'=>count($missing)===0?'DEV_DOCTOR_OK':'DEV_DOCTOR_DEGRADED',
            'time'=>gmdate('c'),
            'php_version'=>PHP_VERSION,
            'extensions'=>[
                'sodium'=>extension_loaded('sodium'),
                'openssl'=>extension_loaded('openssl'),
                'ftp'=>extension_loaded('ftp'),
                'zip'=>extension_loaded('zip'),
            ],
            'source'=>['required'=>count($required),'missing'=>$missing,'files'=>$files],
            'sandbox'=>$sandbox,
            'recent_trace'=>$this->recent(30),
        ];
    }

    private function append(array $row): void
    {
        $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(is_string($json)) @file_put_contents($this->dir.'/trace.jsonl',$json."\n",FILE_APPEND|LOCK_EX);
    }

    private function cleanId(string $v): string
    {
        return preg_match('/^op-[a-f0-9]{16}$/',$v)?$v:'op-invalid';
    }

    private function cleanCode(string $v): string
    {
        $v=strtoupper(trim($v));
        return preg_match('/^[A-Z0-9_.:-]{1,80}$/',$v)?$v:'UNKNOWN';
    }

    /** @return array<string,mixed> */
    private function sanitize(array $detail): array
    {
        $out=[];
        foreach($detail as $k=>$v){
            $key=strtolower((string)$k);
            if(preg_match('/token|secret|password|credential|otp|path|root|directory|session|key/',$key)) continue;
            if(is_bool($v)||is_int($v)||is_float($v)||$v===null){$out[(string)$k]=$v;continue;}
            if(is_string($v)){$out[(string)$k]=mb_substr($v,0,180);continue;}
        }
        return $out;
    }
}
