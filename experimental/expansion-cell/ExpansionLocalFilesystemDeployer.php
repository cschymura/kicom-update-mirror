<?php
declare(strict_types=1);

/**
 * Deploy a prepared Expansion Cell directly into a local web root.
 *
 * Intended for sibling vhosts/webroots on the same hosting account where KiCom
 * already has filesystem write authority. It never guesses a target path: the
 * caller must provide the exact authorized web root. Absolute server paths are
 * deliberately never returned in public result arrays.
 *
 * Public cell files use ordinary shared-hosting web permissions (0755/0644).
 * Private bootstrap/state material stays 0600 below a 0700 var directory.
 */
final class KiComExpansionLocalFilesystemDeployer
{
    /** @return array<string,mixed> */
    public function deploy(string $localCellDir,string $targetWebRoot): array
    {
        $localCellDir=rtrim($localCellDir,'/');
        if(!is_dir($localCellDir)) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_PACKAGE_MISSING'];

        $root=$this->normalizeExistingDirectory($targetWebRoot);
        if($root===null) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_WEBROOT_INVALID'];
        if(!is_writable($root)) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_WEBROOT_NOT_WRITABLE'];

        $target=$root.'/kicom';
        if(file_exists($target)) return ['ok'=>false,'code'=>'EXPANSION_TARGET_EXISTS','target'=>'kicom/'];

        $stage=$root.'/.kicom-stage-'.bin2hex(random_bytes(6));
        if(!@mkdir($stage,0700,true)) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_STAGE_CREATE_FAILED'];

        $inventory=$this->inventory($localCellDir);
        if(empty($inventory['ok'])) { $this->rmTree($stage); return $inventory; }

        $copied=0;
        try {
            foreach($inventory['files'] as $row){
                $rel=(string)$row['path'];
                $src=$localCellDir.'/'.$rel;
                $dst=$stage.'/'.$rel;
                $parent=dirname($dst);
                if(!is_dir($parent)&&!@mkdir($parent,0700,true)&&!is_dir($parent)){
                    return ['ok'=>false,'code'=>'EXPANSION_LOCAL_DIRECTORY_CREATE_FAILED','path'=>$rel,'files'=>$copied];
                }
                if(!@copy($src,$dst)) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_COPY_FAILED','path'=>$rel,'files'=>$copied];
                @chmod($dst,0600);
                $actual=hash_file('sha256',$dst)?:'';
                if(!hash_equals((string)$row['sha256'],$actual)) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_COPY_HASH_MISMATCH','path'=>$rel,'files'=>$copied];
                $copied++;
            }

            $perm=$this->applyServingPermissions($stage);
            if(empty($perm['ok'])) return $perm+['files'=>$copied];

            if(!@rename($stage,$target)) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_ACTIVATE_RENAME_FAILED','files'=>$copied];
            $perm=$this->applyServingPermissions($target);
            if(empty($perm['ok'])) return $perm+['files'=>$copied];

            return [
                'ok'=>true,
                'code'=>'EXPANSION_DEPLOYED_LOCAL',
                'transport'=>'local-filesystem',
                'target'=>'kicom/',
                'files'=>$copied,
                'tree_sha256'=>$inventory['tree_sha256'],
            ];
        } finally {
            if(is_dir($stage)) $this->rmTree($stage);
        }
    }

    /**
     * Repair only a directory that proves it is one of our Expansion Cells.
     * This is used when deployment succeeded but the first HTTPS bootstrap was
     * blocked by shared-hosting permissions. No files are replaced or deleted.
     *
     * @return array<string,mixed>
     */
    public function repairExisting(string $targetWebRoot): array
    {
        $root=$this->normalizeExistingDirectory($targetWebRoot);
        if($root===null) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_WEBROOT_INVALID'];
        $target=$root.'/kicom';
        if(!is_dir($target)) return ['ok'=>false,'code'=>'EXPANSION_EXISTING_CELL_NOT_FOUND'];
        if(!is_file($target.'/cell-manifest.json')||!is_file($target.'/bootstrap.php')||!is_file($target.'/federation.php')){
            return ['ok'=>false,'code'=>'EXPANSION_EXISTING_TARGET_UNMANAGED'];
        }

        $perm=$this->applyServingPermissions($target);
        if(empty($perm['ok'])) return $perm;

        $expansionId='';$childBase='';$state='unknown';
        $cfg=$target.'/bootstrap.config.php';
        if(is_file($cfg)){
            $row=@include $cfg;
            if(is_array($row)){
                $expansionId=(string)($row['expansion_id']??'');
                $childBase=rtrim((string)($row['base_url']??''),'/');
                $state='bootstrap_pending';
            }
        }
        if($expansionId===''&&is_file($target.'/var/bootstrap.private.json')){
            $boot=json_decode((string)@file_get_contents($target.'/var/bootstrap.private.json'),true);
            $node=json_decode((string)@file_get_contents($target.'/var/node.json'),true);
            if(is_array($boot)) $expansionId=(string)($boot['expansion_id']??'');
            if(is_array($node)) $childBase=rtrim((string)($node['base_url']??''),'/');
            $state='enrolling';
        }
        if($childBase===''&&is_file($target.'/var/node.json')){
            $node=json_decode((string)@file_get_contents($target.'/var/node.json'),true);
            if(is_array($node)){
                $childBase=rtrim((string)($node['base_url']??''),'/');
                $state=(string)($node['state']??$state);
            }
        }

        if($expansionId!==''&&!preg_match('/^exp-[a-f0-9]{24}$/',$expansionId)) return ['ok'=>false,'code'=>'EXPANSION_EXISTING_CELL_ID_INVALID'];
        if($childBase!==''){
            $p=parse_url($childBase);
            if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])) return ['ok'=>false,'code'=>'EXPANSION_EXISTING_CELL_URL_INVALID'];
        }

        return [
            'ok'=>true,
            'code'=>'EXPANSION_EXISTING_CELL_REPAIRED',
            'transport'=>'local-filesystem',
            'target'=>'kicom/',
            'expansion_id'=>$expansionId,
            'child_base_url'=>$childBase,
            'state_hint'=>$state,
        ];
    }

    /** @return array{ok:bool,code:string} */
    private function applyServingPermissions(string $cellRoot): array
    {
        $cellRoot=$this->normalizeExistingDirectory($cellRoot);
        if($cellRoot===null) return ['ok'=>false,'code'=>'EXPANSION_PERMISSION_ROOT_INVALID'];

        if(!@chmod($cellRoot,0755)) return ['ok'=>false,'code'=>'EXPANSION_PERMISSION_ROOT_FAILED'];
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cellRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $entry){
            $full=$entry->getPathname();
            $rel=ltrim(str_replace('\\','/',substr($full,strlen($cellRoot))),'/');
            $private=$rel==='var'||str_starts_with($rel,'var/');
            if($entry->isDir()){
                $mode=$private?0700:0755;
                if(!@chmod($full,$mode)) return ['ok'=>false,'code'=>'EXPANSION_PERMISSION_DIR_FAILED','path'=>$rel];
                continue;
            }
            if(!$entry->isFile()) continue;
            $mode=($private||$rel==='bootstrap.config.php')?0600:0644;
            if(!@chmod($full,$mode)) return ['ok'=>false,'code'=>'EXPANSION_PERMISSION_FILE_FAILED','path'=>$rel];
        }
        return ['ok'=>true,'code'=>'EXPANSION_PERMISSIONS_READY'];
    }

    /** @return array{ok:bool,code:string,files?:array<int,array<string,mixed>>,tree_sha256?:string} */
    private function inventory(string $root): array
    {
        $files=[];
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){
            if(!$file instanceof SplFileInfo||!$file->isFile()||$file->isLink()) continue;
            $full=$file->getPathname();
            $rel=ltrim(str_replace('\\','/',substr($full,strlen($root))),'/');
            if(!preg_match('/^[A-Za-z0-9_.\/-]{1,240}$/',$rel)||str_contains('/'.$rel.'/','/../')) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_PATH_INVALID'];
            $size=$file->getSize();
            if($size<0||$size>8*1024*1024) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_FILE_TOO_LARGE'];
            $files[]=['path'=>$rel,'bytes'=>$size,'sha256'=>hash_file('sha256',$full)?:''];
        }
        usort($files,static fn(array $a,array $b): int=>strcmp((string)$a['path'],(string)$b['path']));
        $tree=''; foreach($files as $row) $tree.=$row['sha256'].'  '.$row['path']."\n";
        return ['ok'=>true,'code'=>'EXPANSION_PACKAGE_READY','files'=>$files,'tree_sha256'=>hash('sha256',$tree)];
    }

    private function normalizeExistingDirectory(string $path): ?string
    {
        $path=trim($path);
        if($path===''||str_contains($path,"\0")) return null;
        $real=realpath($path);
        if($real===false||!is_dir($real)) return null;
        return rtrim(str_replace('\\','/',$real),'/');
    }

    private function rmTree(string $dir): void
    {
        if(!is_dir($dir)) return;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);} @rmdir($dir);
    }
}
