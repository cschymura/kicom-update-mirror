<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionProtocol.php';

/** Persistent federation identity for the parent/root KiCom node. */
final class KiComExpansionParentIdentity
{
    private string $dir;
    private string $baseUrl;

    public function __construct(string $storageDir,string $baseUrl)
    {
        $this->dir=rtrim($storageDir,'/');
        $this->baseUrl=rtrim(trim($baseUrl),'/');
        $p=parse_url($this->baseUrl);
        if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])) throw new InvalidArgumentException('PARENT_BASE_URL_INVALID');
        if(!is_dir($this->dir)&&!@mkdir($this->dir,0700,true)&&!is_dir($this->dir)) throw new RuntimeException('PARENT_IDENTITY_STORAGE_UNAVAILABLE');
        @chmod($this->dir,0700);
    }

    /** @return array<string,mixed> */
    public function ensure(): array
    {
        $node=$this->readJson($this->dir.'/node.json');
        if($node!==null){
            if(!hash_equals((string)($node['base_url']??''),$this->baseUrl)) return ['ok'=>false,'code'=>'PARENT_BASE_URL_CONFLICT'];
            if(!is_file($this->dir.'/signing.secret')) return ['ok'=>false,'code'=>'PARENT_SIGNING_SECRET_MISSING'];
            return ['ok'=>true,'code'=>'PARENT_IDENTITY_READY','parent'=>$node];
        }
        $id=KiComExpansionProtocol::createIdentity();
        $secret=KiComExpansionProtocol::b64urlDecode($id['secret_key']);
        if($secret===null||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) return ['ok'=>false,'code'=>'PARENT_IDENTITY_CREATE_FAILED'];
        if(@file_put_contents($this->dir.'/signing.secret',$secret,LOCK_EX)===false){if(function_exists('sodium_memzero'))sodium_memzero($secret);return ['ok'=>false,'code'=>'PARENT_SECRET_WRITE_FAILED'];}
        @chmod($this->dir.'/signing.secret',0600);if(function_exists('sodium_memzero'))sodium_memzero($secret);
        $node=['schema'=>1,'cell_id'=>$id['cell_id'],'root_id'=>$id['cell_id'],'parent_id'=>null,'generation'=>0,'public_key'=>$id['public_key'],'base_url'=>$this->baseUrl,'state'=>'active','capabilities'=>['federation.parent','expansion.create'],'created_at'=>gmdate('c')];
        if(!$this->writeJson($this->dir.'/node.json',$node)) return ['ok'=>false,'code'=>'PARENT_DESCRIPTOR_WRITE_FAILED'];
        return ['ok'=>true,'code'=>'PARENT_IDENTITY_CREATED','parent'=>$node];
    }

    /** @return array<string,mixed> */
    public function descriptor(): array
    {
        $x=$this->ensure();if(empty($x['ok']))return $x;return (array)$x['parent'];
    }

    public function secretKeyB64(): string
    {
        $x=$this->ensure();if(empty($x['ok']))throw new RuntimeException((string)$x['code']);
        $raw=@file_get_contents($this->dir.'/signing.secret');
        if(!is_string($raw)||strlen($raw)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('PARENT_SIGNING_SECRET_INVALID');
        $b64=KiComExpansionProtocol::b64urlEncode($raw);if(function_exists('sodium_memzero'))sodium_memzero($raw);return $b64;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path):?array{$raw=@file_get_contents($path);if(!is_string($raw))return null;$x=json_decode($raw,true);return is_array($x)?$x:null;}
    /** @param array<string,mixed> $row */
    private function writeJson(string $path,array $row):bool{$j=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($j))return false;$tmp=$path.'.tmp.'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$j."\n",LOCK_EX)===false)return false;@chmod($tmp,0600);if(!@rename($tmp,$path)){@unlink($tmp);return false;}@chmod($path,0600);return true;}
}
