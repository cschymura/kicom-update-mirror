<?php
declare(strict_types=1);

/**
 * Narrow, rollback-capable updater for an already managed Expansion Cell.
 *
 * This is deliberately not a general filesystem writer. It accepts only a
 * KiCom-managed /kicom child beneath an already-resolved allowlisted webroot,
 * preserves var/ identity and mutable state, and writes only the fixed runtime
 * files listed below. Existing living state is snapshotted before an in-place
 * architecture upgrade; snapshots are retained rather than hard-deleted.
 */
final class KiComExpansionManagedCellUpdater
{
    /** @return array<string,mixed> */
    public function replaceFederationEndpoint(
        string $targetWebRoot,
        string $sourceFile,
        string $expectedBeforeSha256,
        string $expectedChildBaseUrl
    ): array {
        $root=$this->normalizeExistingDirectory($targetWebRoot);
        if($root===null) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_WEBROOT_INVALID'];

        $cell=$root.'/kicom';
        if(!$this->managedCell($cell)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_TARGET_UNMANAGED'];

        $expectedChildBaseUrl=rtrim(trim($expectedChildBaseUrl),'/');
        $node=$this->readJson($cell.'/var/node.json');
        $guard=$this->validateActiveNode($node,$expectedChildBaseUrl);
        if(empty($guard['ok'])) return $guard;

        $target=$cell.'/federation.php';
        if(!is_file($target)||is_link($target)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_TARGET_INVALID'];
        if(!is_file($sourceFile)||is_link($sourceFile)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_SOURCE_INVALID'];

        $source=@file_get_contents($sourceFile);
        $before=@file_get_contents($target);
        if(!is_string($source)||!is_string($before)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_READ_FAILED'];
        if(strlen($source)<32||strlen($source)>65536) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_SOURCE_SIZE_INVALID'];
        try { token_get_all($source,TOKEN_PARSE); } catch(ParseError $e) { return ['ok'=>false,'code'=>'EXPANSION_REPAIR_SOURCE_SYNTAX_INVALID']; }

        $sourceSha=hash('sha256',$source);
        $beforeSha=hash('sha256',$before);
        $expectedBeforeSha256=strtolower(trim($expectedBeforeSha256));
        if(!preg_match('/^[a-f0-9]{64}$/',$expectedBeforeSha256)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_EXPECTED_HASH_INVALID'];
        if(hash_equals($sourceSha,$beforeSha)) {
            return ['ok'=>true,'code'=>'EXPANSION_REPAIR_ALREADY_CURRENT','target'=>'kicom/federation.php','before_sha256'=>$beforeSha,'after_sha256'=>$sourceSha,'changed'=>false];
        }
        if(!hash_equals($expectedBeforeSha256,$beforeSha)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_HASH_CONFLICT','current_sha256'=>$beforeSha];

        $write=$this->atomicWrite($target,$source,0644);
        if(empty($write['ok'])) return $write;

        return [
            'ok'=>true,
            'code'=>'EXPANSION_REPAIR_FILE_UPDATED',
            'target'=>'kicom/federation.php',
            'before_sha256'=>$beforeSha,
            'after_sha256'=>$sourceSha,
            'changed'=>true,
            'rollback_content'=>$before,
        ];
    }

    /** @param array<string,mixed> $update @return array<string,mixed> */
    public function rollbackFederationEndpoint(string $targetWebRoot,array $update): array
    {
        $root=$this->normalizeExistingDirectory($targetWebRoot);
        if($root===null) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_ROLLBACK_WEBROOT_INVALID'];
        $cell=$root.'/kicom';
        if(!$this->managedCell($cell)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_ROLLBACK_TARGET_UNMANAGED'];
        $target=$cell.'/federation.php';
        if(!is_file($target)||is_link($target)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_ROLLBACK_TARGET_INVALID'];

        $expected=(string)($update['after_sha256']??'');
        $backup=$update['rollback_content']??null;
        if(!preg_match('/^[a-f0-9]{64}$/',$expected)||!is_string($backup)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_ROLLBACK_INPUT_INVALID'];
        $current=hash_file('sha256',$target)?:'';
        if(!hash_equals($expected,$current)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_ROLLBACK_CONFLICT','current_sha256'=>$current];
        $write=$this->atomicWrite($target,$backup,0644);
        if(empty($write['ok'])) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_ROLLBACK_WRITE_FAILED'];
        return ['ok'=>true,'code'=>'EXPANSION_REPAIR_ROLLED_BACK','sha256'=>hash('sha256',$backup)];
    }

    /**
     * Upgrade an active managed child to the current intrinsic Living runtime.
     * Existing Living cells are upgraded in place with a retained state snapshot;
     * thin cells still receive a first intrinsic Living birth.
     *
     * @return array<string,mixed>
     */
    public function upgradeLivingRuntime(string $targetWebRoot,string $sourceDir,string $expectedChildBaseUrl): array
    {
        $root=$this->normalizeExistingDirectory($targetWebRoot);
        if($root===null) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_WEBROOT_INVALID'];
        $cell=$root.'/kicom';
        if(!$this->managedCell($cell)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_TARGET_UNMANAGED'];

        $node=$this->readJson($cell.'/var/node.json');
        $guard=$this->validateActiveNode($node,rtrim(trim($expectedChildBaseUrl),'/'));
        if(empty($guard['ok'])) return ['ok'=>false,'code'=>str_replace('EXPANSION_REPAIR_','EXPANSION_UPGRADE_',($guard['code']??'EXPANSION_REPAIR_NODE_INVALID'))];

        $sourceDir=rtrim($sourceDir,'/');
        $map=[
            'ExpansionProtocol.php'=>'lib/ExpansionProtocol.php',
            'CellNode.php'=>'lib/CellNode.php',
            'CellLiving.php'=>'lib/CellLiving.php',
            'CellPerceptionAction.php'=>'lib/CellPerceptionAction.php',
            'CellWorldModel.php'=>'lib/CellWorldModel.php',
            'cell-runtime/common.php'=>'common.php',
            'cell-runtime/bootstrap.php'=>'bootstrap.php',
            'cell-runtime/federation.php'=>'federation.php',
            'cell-runtime/status.php'=>'status.php',
            'cell-runtime/doctor.php'=>'doctor.php',
            'cell-runtime/living-schema.json'=>'living-schema.json',
        ];
        $sources=[];
        foreach($map as $srcRel=>$dstRel){
            $src=$sourceDir.'/'.$srcRel;
            if(!is_file($src)||is_link($src)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_SOURCE_MISSING','path'=>$srcRel];
            $raw=@file_get_contents($src);
            if(!is_string($raw)||strlen($raw)<2||strlen($raw)>262144) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_SOURCE_INVALID','path'=>$srcRel];
            if(str_ends_with(strtolower($srcRel),'.php')){
                try{token_get_all($raw,TOKEN_PARSE);}catch(ParseError $e){return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_SOURCE_SYNTAX_INVALID','path'=>$srcRel];}
            } elseif($srcRel==='cell-runtime/living-schema.json') {
                $j=json_decode($raw,true);if(!is_array($j)||($j['schema']??0)!==1) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_SCHEMA_INVALID'];
                foreach(['perception','action'] as $sub)if(!in_array($sub,$j['required_subsystems']??[],true))return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_SCHEMA_SUBSYSTEM_MISSING','subsystem'=>$sub];
                if(($j['world_model']??'')!=='persistent-situational-awareness')return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_WORLD_MODEL_SCHEMA_MISSING'];
            }
            $sources[$dstRel]=['content'=>$raw,'sha256'=>hash('sha256',$raw)];
        }

        $manifest=$this->readJson($cell.'/cell-manifest.json');
        if($manifest===null||!is_array($manifest['files']??null)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_MANIFEST_INVALID'];
        foreach($manifest['files'] as $entry){
            if(!is_array($entry)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_MANIFEST_INVALID'];
            $rel=(string)($entry['path']??'');$sha=strtolower((string)($entry['sha256']??''));
            if($rel===''||!preg_match('/^[a-f0-9]{64}$/',$sha)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_MANIFEST_INVALID'];
            if($rel==='bootstrap.config.php') continue;
            $target=$cell.'/'.$rel;
            if(!is_file($target)||is_link($target)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_MANAGED_FILE_MISSING','path'=>$rel];
            $actual=hash_file('sha256',$target)?:'';
            if(!hash_equals($sha,$actual)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_MANAGED_DRIFT','path'=>$rel,'current_sha256'=>$actual];
        }

        $livingExisted=is_dir($cell.'/var/living');
        $allRuntimeCurrent=true;
        foreach($sources as $rel=>$src){$target=$cell.'/'.$rel;if(!is_file($target)||!hash_equals((string)$src['sha256'],hash_file('sha256',$target)?:'')){$allRuntimeCurrent=false;break;}}
        if($livingExisted&&$allRuntimeCurrent){
            require_once $sourceDir.'/CellLiving.php';
            require_once $sourceDir.'/CellWorldModel.php';
            $ls=(new KiComExpansionCellLiving($cell.'/var'))->status();
            $ws=(new KiComExpansionCellWorldModel($cell.'/var'))->status();
            if(!empty($ls['living_ready'])&&!empty($ls['perception_action']['ready'])&&!empty($ws['ready'])) return ['ok'=>true,'code'=>'EXPANSION_LIVING_ALREADY_CURRENT','changed'=>false,'living'=>$ls,'world_model'=>$ws,'cell_id'=>$node['cell_id']];
        }

        $snapshotId=null;$snapshotDir=null;
        if($livingExisted){
            $snapshotId=gmdate('YmdHis').'-'.substr(hash('sha256',(string)$node['cell_id'].'|'.microtime(true)),0,10);
            $snapshotDir=$cell.'/var/living_snapshots/'.$snapshotId;
            if(!$this->copyTreeBounded($cell.'/var/living',$snapshotDir,5000,33554432)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_LIVING_SNAPSHOT_FAILED'];
        }

        $backups=[];$written=[];
        $manifestRaw=@file_get_contents($cell.'/cell-manifest.json');
        if(!is_string($manifestRaw)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_MANIFEST_READ_FAILED'];
        $backups['cell-manifest.json']=['exists'=>true,'content'=>$manifestRaw,'sha256'=>hash('sha256',$manifestRaw)];
        foreach($sources as $rel=>$src){
            $target=$cell.'/'.$rel;
            $raw=is_file($target)?@file_get_contents($target):false;
            if($raw===false)$backups[$rel]=['exists'=>false,'content'=>'','sha256'=>'NEW'];
            else $backups[$rel]=['exists'=>true,'content'=>(string)$raw,'sha256'=>hash('sha256',(string)$raw)];
        }

        foreach($sources as $rel=>$src){
            $target=$cell.'/'.$rel;$mode=str_starts_with($rel,'lib/')?0600:0644;
            $w=$this->atomicWrite($target,(string)$src['content'],$mode);
            if(empty($w['ok'])){
                $rb=$this->restoreUpgradeState($cell,$backups,$written,$livingExisted,$snapshotDir,$snapshotId);
                return ['ok'=>false,'code'=>!empty($rb['ok'])?'EXPANSION_UPGRADE_WRITE_FAILED_ROLLED_BACK':'EXPANSION_UPGRADE_WRITE_FAILED_ROLLBACK_FAILED','path'=>$rel,'rollback'=>$rb];
            }
            $written[]=$rel;
        }

        require_once $sourceDir.'/CellLiving.php';
        require_once $sourceDir.'/CellPerceptionAction.php';
        require_once $sourceDir.'/CellWorldModel.php';
        $living=new KiComExpansionCellLiving($cell.'/var');
        if($livingExisted){
            $transition=$living->rebaselineRuntime((array)$node);
        } else {
            $transition=$living->initialize([
                'cell_id'=>(string)$node['cell_id'],
                'base_url'=>(string)$node['base_url'],
                'parent_id'=>(string)($node['parent_id']??''),
                'capabilities'=>$node['capabilities']??[],
            ]);
            if(!empty($transition['ok'])&&!$living->recordActivation((array)$node))$transition=['ok'=>false,'code'=>'CELL_LIVING_ACTIVATION_WRITE_FAILED'];
        }
        if(!empty($transition['ok'])){
            $pa=new KiComExpansionCellPerceptionAction($cell.'/var');
            $paReady=$pa->ensure((array)$node);
            $paCycle=!empty($paReady['ok'])?$pa->cycle((array)$node,'managed_living_upgrade'):['ok'=>false,'code'=>'CELL_PA_ENSURE_FAILED'];
            if(empty($paReady['ok'])||empty($paCycle['ok']))$transition=['ok'=>false,'code'=>'CELL_PA_MANAGED_UPGRADE_FAILED','ensure'=>$paReady,'cycle'=>$paCycle];
        }
        $worldStatus=['ok'=>false,'code'=>'CELL_WORLD_NOT_ATTEMPTED'];
        if(!empty($transition['ok'])){
            $world=new KiComExpansionCellWorldModel($cell.'/var');
            $worldReady=$world->ensure((array)$node);
            $worldCycle=!empty($worldReady['ok'])?$world->refresh((array)$node,'managed_world_upgrade'):['ok'=>false,'code'=>'CELL_WORLD_ENSURE_FAILED'];
            $worldStatus=$world->status();
            if(empty($worldReady['ok'])||empty($worldCycle['ok'])||empty($worldStatus['ready']))$transition=['ok'=>false,'code'=>'CELL_WORLD_MANAGED_UPGRADE_FAILED','ensure'=>$worldReady,'cycle'=>$worldCycle,'status'=>$worldStatus];
        }
        if(empty($transition['ok'])){
            $rb=$this->restoreUpgradeState($cell,$backups,$written,$livingExisted,$snapshotDir,$snapshotId);
            return ['ok'=>false,'code'=>!empty($rb['ok'])?'EXPANSION_UPGRADE_LIVING_FAILED_ROLLED_BACK':'EXPANSION_UPGRADE_LIVING_FAILED_ROLLBACK_FAILED','living'=>$transition,'rollback'=>$rb];
        }

        $ls=$living->status();
        if(empty($ls['living_ready'])||empty($ls['perception_action']['ready'])||empty($worldStatus['ready'])){
            $rb=$this->restoreUpgradeState($cell,$backups,$written,$livingExisted,$snapshotDir,$snapshotId);
            return ['ok'=>false,'code'=>!empty($rb['ok'])?'EXPANSION_UPGRADE_VERIFY_FAILED_ROLLED_BACK':'EXPANSION_UPGRADE_VERIFY_FAILED_ROLLBACK_FAILED','living'=>$ls,'world_model'=>$worldStatus,'rollback'=>$rb];
        }

        $rows=[];
        foreach($sources as $rel=>$src)$rows[]=['path'=>$rel,'bytes'=>strlen((string)$src['content']),'sha256'=>(string)$src['sha256']];
        usort($rows,static fn(array $a,array $b):int=>strcmp((string)$a['path'],(string)$b['path']));
        $tree='';foreach($rows as $r)$tree.=$r['sha256'].'  '.$r['path']."\n";
        $newManifest=[
            'schema'=>4,
            'managed_upgrade'=>'living-v3-situational-world-model',
            'cell_id'=>(string)$node['cell_id'],
            'base_url'=>rtrim((string)$node['base_url'],'/'),
            'mutable_state'=>'var-preserved-and-snapshotted',
            'files'=>$rows,
            'tree_sha256'=>hash('sha256',$tree),
            'upgraded_at'=>gmdate('c'),
            'perception_action_memory'=>true,
            'world_model'=>true,
            'prior_living_snapshot_id'=>$snapshotId,
        ];
        $manifestJson=json_encode($newManifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($manifestJson)||empty($this->atomicWrite($cell.'/cell-manifest.json',$manifestJson."\n",0644)['ok'])){
            $rb=$this->restoreUpgradeState($cell,$backups,$written,$livingExisted,$snapshotDir,$snapshotId);
            return ['ok'=>false,'code'=>!empty($rb['ok'])?'EXPANSION_UPGRADE_MANIFEST_WRITE_FAILED_ROLLED_BACK':'EXPANSION_UPGRADE_MANIFEST_WRITE_FAILED_ROLLBACK_FAILED','rollback'=>$rb];
        }
        $written[]='cell-manifest.json';

        return [
            'ok'=>true,
            'code'=>$livingExisted?'EXPANSION_LIVING_WORLD_UPGRADE_APPLIED':'EXPANSION_LIVING_UPGRADE_APPLIED',
            'changed'=>true,
            'cell_id'=>(string)$node['cell_id'],
            'files'=>count($rows),
            'tree_sha256'=>$newManifest['tree_sha256'],
            'living'=>$ls,
            'perception_action_ready'=>true,
            'world_model_ready'=>true,
            'world_model'=>$worldStatus,
            'snapshot_id'=>$snapshotId,
            '_rollback'=>[
                'backups'=>$backups,'written'=>$written,'living_created'=>!$livingExisted,
                'living_existed'=>$livingExisted,'snapshot_dir'=>$snapshotDir,'snapshot_id'=>$snapshotId,
            ],
        ];
    }

    /** @param array<string,mixed> $upgrade @return array<string,mixed> */
    public function rollbackLivingRuntime(string $targetWebRoot,array $upgrade): array
    {
        $root=$this->normalizeExistingDirectory($targetWebRoot);
        if($root===null) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_ROLLBACK_WEBROOT_INVALID'];
        $cell=$root.'/kicom';
        $r=$upgrade['_rollback']??null;
        if(!is_array($r)||!is_array($r['backups']??null)||!is_array($r['written']??null)) return ['ok'=>false,'code'=>'EXPANSION_UPGRADE_ROLLBACK_INPUT_INVALID'];
        return $this->restoreUpgradeState(
            $cell,(array)$r['backups'],(array)$r['written'],!empty($r['living_existed']),
            is_string($r['snapshot_dir']??null)?(string)$r['snapshot_dir']:null,
            is_string($r['snapshot_id']??null)?(string)$r['snapshot_id']:null
        );
    }

    /** @param array<string,mixed>|null $node @return array<string,mixed> */
    private function validateActiveNode(?array $node,string $expectedChildBaseUrl): array
    {
        if($node===null||($node['state']??'')!=='active') return ['ok'=>false,'code'=>'EXPANSION_REPAIR_CELL_NOT_ACTIVE'];
        $actualBase=rtrim((string)($node['base_url']??''),'/');
        if($expectedChildBaseUrl===''||!hash_equals($expectedChildBaseUrl,$actualBase)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_CELL_BASE_MISMATCH'];
        if(!preg_match('/^cell-[a-f0-9]{24}$/',(string)($node['cell_id']??''))) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_CELL_ID_INVALID'];
        if(!preg_match('/^cell-[a-f0-9]{24}$/',(string)($node['parent_id']??''))) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_PARENT_ID_INVALID'];
        return ['ok'=>true,'code'=>'EXPANSION_REPAIR_NODE_OK'];
    }

    /** @param array<string,array<string,mixed>> $backups @param list<string> $written */
    private function restoreUpgradeState(string $cell,array $backups,array $written,bool $livingExisted,?string $snapshotDir,?string $snapshotId): array
    {
        $files=$this->restoreFiles($cell,$backups,$written,false);
        $livingOk=true;$archive=null;
        if($livingExisted&&is_string($snapshotDir)&&is_dir($snapshotDir)){
            $failedRoot=$cell.'/var/living_failed';if(!is_dir($failedRoot))@mkdir($failedRoot,0700,true);
            $archive=$failedRoot.'/'.($snapshotId?:gmdate('YmdHis'));
            if(is_dir($cell.'/var/living')){
                if(is_dir($archive))$archive.='-'.substr(hash('sha256',microtime(true).''),0,6);
                if(!@rename($cell.'/var/living',$archive))$livingOk=false;
            }
            if($livingOk&&!$this->copyTreeBounded($snapshotDir,$cell.'/var/living',5000,33554432))$livingOk=false;
        } elseif(!$livingExisted&&is_dir($cell.'/var/living')) {
            $failedRoot=$cell.'/var/living_failed';if(!is_dir($failedRoot))@mkdir($failedRoot,0700,true);
            $archive=$failedRoot.'/'.gmdate('YmdHis').'-new-living';
            if(!@rename($cell.'/var/living',$archive))$livingOk=false;
        }
        return [
            'ok'=>!empty($files['ok'])&&$livingOk,
            'code'=>!empty($files['ok'])&&$livingOk?'EXPANSION_UPGRADE_ROLLED_BACK':'EXPANSION_UPGRADE_ROLLBACK_INCOMPLETE',
            'restored'=>$files['restored']??[],
            'living_restored'=>$livingOk,
            'failed_living_archive'=>$archive,
            'snapshot_retained'=>$snapshotDir,
        ];
    }

    /** @param array<string,array<string,mixed>> $backups @param list<string> $written */
    private function restoreFiles(string $cell,array $backups,array $written,bool $unused): array
    {
        $ok=true;$restored=[];
        foreach(array_reverse($written) as $rel){
            if(!isset($backups[$rel])) continue;
            $target=$cell.'/'.$rel;$b=$backups[$rel];
            if(empty($b['exists'])){
                if(is_file($target)&&!@unlink($target)){$ok=false;continue;}
                $restored[]=$rel;continue;
            }
            $content=$b['content']??null;
            if(!is_string($content)||!hash_equals((string)($b['sha256']??''),hash('sha256',$content))){$ok=false;continue;}
            $mode=str_starts_with($rel,'lib/')?0600:0644;
            $w=$this->atomicWrite($target,$content,$mode);if(empty($w['ok'])){$ok=false;continue;}$restored[]=$rel;
        }
        return ['ok'=>$ok,'code'=>$ok?'EXPANSION_UPGRADE_FILES_ROLLED_BACK':'EXPANSION_UPGRADE_FILE_ROLLBACK_INCOMPLETE','restored'=>$restored];
    }

    /** @return array{ok:bool,code:string,sha256?:string} */
    private function atomicWrite(string $target,string $content,int $mode): array
    {
        $dir=dirname($target);
        if(!is_dir($dir)&&!@mkdir($dir,0755,true)&&!is_dir($dir)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_DIRECTORY_CREATE_FAILED'];
        if(!is_writable($dir)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_DIRECTORY_NOT_WRITABLE'];
        $tmp=$dir.'/.kicom-repair-'.bin2hex(random_bytes(6)).'.tmp';
        if(@file_put_contents($tmp,$content,LOCK_EX)===false) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_TEMP_WRITE_FAILED'];
        @chmod($tmp,0600);
        $sha=hash_file('sha256',$tmp)?:'';
        if(!hash_equals(hash('sha256',$content),$sha)){@unlink($tmp);return ['ok'=>false,'code'=>'EXPANSION_REPAIR_TEMP_HASH_MISMATCH'];}
        if(!@rename($tmp,$target)){@unlink($tmp);return ['ok'=>false,'code'=>'EXPANSION_REPAIR_RENAME_FAILED'];}
        @chmod($target,$mode);
        clearstatcache(true,$target);
        if(function_exists('opcache_invalidate')) @opcache_invalidate($target,true);
        return ['ok'=>true,'code'=>'EXPANSION_REPAIR_ATOMIC_WRITE_OK','sha256'=>$sha];
    }

    private function copyTreeBounded(string $src,string $dst,int $maxFiles,int $maxBytes): bool
    {
        if(!is_dir($src)||is_link($src))return false;
        if(is_dir($dst))return false;
        if(!@mkdir($dst,0700,true)&&!is_dir($dst))return false;@chmod($dst,0700);
        $files=0;$bytes=0;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $f){
            if($f->isLink())return false;
            $rel=ltrim(str_replace('\\','/',substr($f->getPathname(),strlen($src))),'/');
            if($rel===''||str_contains($rel,'..'))return false;
            $to=$dst.'/'.$rel;
            if($f->isDir()){
                if(!is_dir($to)&&!@mkdir($to,0700,true)&&!is_dir($to))return false;@chmod($to,0700);continue;
            }
            $files++;$bytes+=(int)$f->getSize();if($files>$maxFiles||$bytes>$maxBytes)return false;
            $dir=dirname($to);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return false;
            if(!@copy($f->getPathname(),$to))return false;@chmod($to,0600);
        }
        return true;
    }

    private function managedCell(string $cell): bool
    {
        return is_dir($cell)
            &&is_file($cell.'/cell-manifest.json')
            &&is_file($cell.'/status.php')
            &&is_file($cell.'/federation.php')
            &&is_file($cell.'/var/node.json')
            &&is_file($cell.'/var/signing.secret');
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if(!is_file($path)||is_link($path)) return null;
        $raw=@file_get_contents($path);
        if(!is_string($raw)||strlen($raw)>262144) return null;
        $row=json_decode($raw,true);
        return is_array($row)?$row:null;
    }

    private function normalizeExistingDirectory(string $path): ?string
    {
        $path=trim($path);
        if($path===''||str_contains($path,"\0")) return null;
        $real=realpath($path);
        if($real===false||!is_dir($real)) return null;
        return rtrim(str_replace('\\','/',$real),'/');
    }
}
