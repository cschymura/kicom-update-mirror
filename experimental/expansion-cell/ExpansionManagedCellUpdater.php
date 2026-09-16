<?php
declare(strict_types=1);

/**
 * Atomic, hash-bound repair of one managed Expansion Cell runtime endpoint.
 *
 * The updater is deliberately not a general filesystem writer. It only accepts
 * the fixed runtime endpoint `federation.php`, requires KiCom cell markers and
 * an active node descriptor, binds the expected current SHA-256, and never
 * returns absolute hosting paths.
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
        if($node===null||($node['state']??'')!=='active') return ['ok'=>false,'code'=>'EXPANSION_REPAIR_CELL_NOT_ACTIVE'];
        $actualBase=rtrim((string)($node['base_url']??''),'/');
        if($expectedChildBaseUrl===''||!hash_equals($expectedChildBaseUrl,$actualBase)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_CELL_BASE_MISMATCH'];
        if(!preg_match('/^cell-[a-f0-9]{24}$/',(string)($node['cell_id']??''))) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_CELL_ID_INVALID'];

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

    /** @return array{ok:bool,code:string,sha256?:string} */
    private function atomicWrite(string $target,string $content,int $mode): array
    {
        $dir=dirname($target);
        if(!is_dir($dir)||!is_writable($dir)) return ['ok'=>false,'code'=>'EXPANSION_REPAIR_DIRECTORY_NOT_WRITABLE'];
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

    private function managedCell(string $cell): bool
    {
        return is_dir($cell)
            &&is_file($cell.'/cell-manifest.json')
            &&is_file($cell.'/status.php')
            &&is_file($cell.'/federation.php')
            &&is_file($cell.'/var/node.json');
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
