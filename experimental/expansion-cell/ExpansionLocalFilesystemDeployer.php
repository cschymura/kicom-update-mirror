<?php
declare(strict_types=1);

/**
 * Deploy a prepared Expansion Cell directly into a local web root.
 *
 * Intended for sibling vhosts/webroots on the same hosting account where KiCom
 * already has filesystem write authority. It never guesses a target path: the
 * caller must provide the exact authorized web root. Absolute server paths are
 * deliberately never returned in public result arrays.
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
            if(!@rename($stage,$target)) return ['ok'=>false,'code'=>'EXPANSION_LOCAL_ACTIVATE_RENAME_FAILED','files'=>$copied];
            @chmod($target,0700);
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
