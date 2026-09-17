<?php
declare(strict_types=1);

/**
 * Materializes a deployable, intrinsically complete child-cell directory for one
 * prepared expansion. Enrollment establishes lineage/trust later; it does not
 * deliver basic daughter-cell capabilities.
 */
final class KiComExpansionCellPackageBuilder
{
    private string $sourceDir;

    public function __construct(string $sourceDir)
    {
        $this->sourceDir=rtrim($sourceDir,'/');
    }

    /** @param array<string,mixed> $prepared @param array<string,mixed> $parent */
    public function build(string $outputDir,array $prepared,array $parent,array $capabilities=['federation.tick','status.report']): array
    {
        foreach (['expansion_id','enrollment_token','child_base_url'] as $key) {
            if (!isset($prepared[$key]) || !is_string($prepared[$key]) || trim($prepared[$key])==='') {
                return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_PREPARED_INVALID'];
            }
        }
        foreach (['cell_id','public_key','base_url'] as $key) {
            if (!isset($parent[$key]) || !is_string($parent[$key]) || trim($parent[$key])==='') {
                return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_PARENT_INVALID'];
            }
        }
        if (!preg_match('/^exp-[a-f0-9]{24}$/',(string)$prepared['expansion_id'])) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_ID_INVALID'];
        $p=parse_url((string)$prepared['child_base_url']);
        if (!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_HTTPS_REQUIRED'];

        // Hidden dotfiles are deliberately generated rather than source-required.
        // Every intrinsic daughter capability, however, must physically exist in
        // the package before deployment.
        $required=[
            'ExpansionProtocol.php',
            'CellNode.php',
            'CellLiving.php',
            'cell-runtime/common.php',
            'cell-runtime/bootstrap.php',
            'cell-runtime/federation.php',
            'cell-runtime/status.php',
            'cell-runtime/doctor.php',
            'cell-runtime/living-schema.json',
        ];
        foreach ($required as $rel) if (!is_file($this->sourceDir.'/'.$rel)) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_SOURCE_MISSING','path'=>$rel];

        if (is_dir($outputDir)) $this->rmTree($outputDir);
        if (!@mkdir($outputDir.'/lib',0700,true) && !is_dir($outputDir.'/lib')) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_DIR_FAILED'];
        if (!@mkdir($outputDir.'/var',0700,true) && !is_dir($outputDir.'/var')) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_VAR_FAILED'];
        @chmod($outputDir,0700); @chmod($outputDir.'/lib',0700); @chmod($outputDir.'/var',0700);

        $copy=[
            'ExpansionProtocol.php'=>'lib/ExpansionProtocol.php',
            'CellNode.php'=>'lib/CellNode.php',
            'CellLiving.php'=>'lib/CellLiving.php',
            'cell-runtime/common.php'=>'common.php',
            'cell-runtime/bootstrap.php'=>'bootstrap.php',
            'cell-runtime/federation.php'=>'federation.php',
            'cell-runtime/status.php'=>'status.php',
            'cell-runtime/doctor.php'=>'doctor.php',
            'cell-runtime/living-schema.json'=>'living-schema.json',
        ];
        foreach ($copy as $src=>$dst) {
            if (!@copy($this->sourceDir.'/'.$src,$outputDir.'/'.$dst)) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_COPY_FAILED','path'=>$src];
            @chmod($outputDir.'/'.$dst,0600);
        }

        $publicDeny="Options -Indexes\n<FilesMatch \"^(bootstrap\\.config\\.php|common\\.php|living-schema\\.json)$\">\n  Require all denied\n</FilesMatch>\n";
        if (@file_put_contents($outputDir.'/.htaccess',$publicDeny,LOCK_EX)===false) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_HTACCESS_FAILED'];
        @chmod($outputDir.'/.htaccess',0600);
        if (@file_put_contents($outputDir.'/var/.htaccess',"Require all denied\n",LOCK_EX)===false) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_VAR_HTACCESS_FAILED'];
        @chmod($outputDir.'/var/.htaccess',0600);

        $caps=[];
        foreach ($capabilities as $cap) {
            $cap=strtolower(trim((string)$cap));
            if ($cap!==''&&preg_match('/^[a-z0-9_.-]{1,64}$/',$cap)) $caps[$cap]=true;
        }
        $seed=[
            'schema'=>1,
            'expansion_id'=>(string)$prepared['expansion_id'],
            'enrollment_token'=>(string)$prepared['enrollment_token'],
            'base_url'=>rtrim((string)$prepared['child_base_url'],'/'),
            'parent_id'=>(string)$parent['cell_id'],
            'parent_public_key'=>(string)$parent['public_key'],
            'parent_base_url'=>rtrim((string)$parent['base_url'],'/'),
            'capabilities'=>array_keys($caps),
        ];
        $config="<?php\ndeclare(strict_types=1);\nreturn ".var_export($seed,true).";\n";
        if (@file_put_contents($outputDir.'/bootstrap.config.php',$config,LOCK_EX)===false) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_CONFIG_FAILED'];
        @chmod($outputDir.'/bootstrap.config.php',0600);

        $manifest=$this->manifest($outputDir);
        $complete=$this->assertCompletePackage($outputDir,$manifest);
        if (empty($complete['ok'])) { $this->rmTree($outputDir); return $complete; }
        $manifest['intrinsic_complete']=true;
        $manifest['required_intrinsic_files']=$complete['required_files'];
        $json=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($json)||@file_put_contents($outputDir.'/cell-manifest.json',$json."\n",LOCK_EX)===false) return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_MANIFEST_FAILED'];
        @chmod($outputDir.'/cell-manifest.json',0600);
        return ['ok'=>true,'code'=>'EXPANSION_PACKAGE_READY','directory'=>$outputDir,'files'=>count($manifest['files']),'tree_sha256'=>$manifest['tree_sha256'],'intrinsic_complete'=>true];
    }

    /** @return array<string,mixed> */
    private function assertCompletePackage(string $root,array $manifest): array
    {
        $required=[
            '.htaccess','var/.htaccess','bootstrap.config.php','common.php','bootstrap.php','federation.php','status.php','doctor.php','living-schema.json',
            'lib/ExpansionProtocol.php','lib/CellNode.php','lib/CellLiving.php',
        ];
        $listed=[];foreach(($manifest['files']??[]) as $row)if(is_array($row)&&isset($row['path']))$listed[(string)$row['path']]=true;
        foreach($required as $path){
            if(!is_file($root.'/'.$path)||!isset($listed[$path]))return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_INTRINSIC_FILE_MISSING','path'=>$path];
        }
        $schemaRaw=@file_get_contents($root.'/living-schema.json');$schema=is_string($schemaRaw)?json_decode($schemaRaw,true):null;
        if(!is_array($schema)||(int)($schema['schema']??0)!==1)return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_LIVING_SCHEMA_INVALID'];
        foreach(['identity','canonical_memory','workspace','observer','genome_lkg','immune','evolution'] as $sub)if(!in_array($sub,$schema['required_subsystems']??[],true))return ['ok'=>false,'code'=>'EXPANSION_PACKAGE_INTRINSIC_SUBSYSTEM_MISSING','subsystem'=>$sub];
        return ['ok'=>true,'required_files'=>$required];
    }

    /** @return array<string,mixed> */
    private function manifest(string $root): array
    {
        $rows=[];
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f instanceof SplFileInfo||!$f->isFile()||$f->isLink()) continue;
            $rel=ltrim(str_replace('\\','/',substr($f->getPathname(),strlen($root))),'/');
            if ($rel==='cell-manifest.json') continue;
            $rows[]=['path'=>$rel,'bytes'=>$f->getSize(),'sha256'=>hash_file('sha256',$f->getPathname())?:''];
        }
        usort($rows,static fn(array $a,array $b): int=>strcmp((string)$a['path'],(string)$b['path']));
        $tree=''; foreach($rows as $row) $tree.=$row['sha256'].'  '.$row['path']."\n";
        return ['schema'=>1,'files'=>$rows,'tree_sha256'=>hash('sha256',$tree),'created_at'=>gmdate('c')];
    }

    public function destroy(string $dir): void { $this->rmTree($dir); }
    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);} @rmdir($dir);
    }
}
