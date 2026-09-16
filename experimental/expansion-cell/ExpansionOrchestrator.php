<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionProtocol.php';
require_once __DIR__.'/ExpansionRegistry.php';
require_once __DIR__.'/ExpansionFtpDeployer.php';
require_once __DIR__.'/ExpansionLocalFilesystemDeployer.php';
require_once __DIR__.'/ExpansionCronRelay.php';
require_once __DIR__.'/ExpansionCellPackageBuilder.php';
require_once __DIR__.'/ExpansionHttpTransport.php';

/** One-shot parent workflow: prepare -> package -> deploy -> enroll -> activate -> tick. */
final class KiComExpansionOrchestrator
{
    private KiComExpansionRegistry $registry;
    private KiComExpansionCellPackageBuilder $builder;
    private KiComExpansionFtpDeployer $ftp;
    /** @var array<string,mixed> */ private array $parent;
    private string $parentSecret;
    private string $workRoot;

    /** @param array<string,mixed> $parent */
    public function __construct(KiComExpansionRegistry $registry,KiComExpansionCellPackageBuilder $builder,KiComExpansionFtpDeployer $ftp,array $parent,string $parentSecretKey,string $workRoot)
    {
        foreach(['cell_id','public_key','base_url','generation'] as $k) if(!isset($parent[$k])) throw new InvalidArgumentException('PARENT_DESCRIPTOR_INVALID');
        $secret=KiComExpansionProtocol::b64urlDecode($parentSecretKey);
        $public=KiComExpansionProtocol::b64urlDecode((string)$parent['public_key']);
        if($secret===null||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES||$public===null||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new InvalidArgumentException('PARENT_KEY_INVALID');
        $derived=sodium_crypto_sign_publickey_from_secretkey($secret);
        $match=hash_equals($public,$derived);
        if(function_exists('sodium_memzero')){sodium_memzero($secret);sodium_memzero($derived);}
        if(!$match) throw new InvalidArgumentException('PARENT_KEY_MISMATCH');
        $this->registry=$registry;$this->builder=$builder;$this->ftp=$ftp;$this->parent=$parent;$this->parentSecret=$parentSecretKey;$this->workRoot=rtrim($workRoot,'/');
        if(!is_dir($this->workRoot)&&!@mkdir($this->workRoot,0700,true)&&!is_dir($this->workRoot)) throw new RuntimeException('EXPANSION_WORKROOT_UNAVAILABLE');
        @chmod($this->workRoot,0700);
    }

    /** @return array<string,mixed> */
    public function preparePackage(string $targetBaseUrl,string $remoteWebRoot='/',int $ttl=3600): array
    {
        $prep=$this->registry->prepare($targetBaseUrl,$remoteWebRoot,$ttl);
        if(empty($prep['ok'])) return $prep;
        $dir=$this->workRoot.'/'.(string)$prep['expansion_id'];
        $pkg=$this->builder->build($dir,$prep,$this->parent);
        if(empty($pkg['ok'])){$this->builder->destroy($dir);return $pkg+['expansion_id'=>$prep['expansion_id']];}
        return ['ok'=>true,'code'=>'EXPANSION_PACKAGE_PREPARED','prepared'=>$prep,'package'=>$pkg];
    }

    /** @param array<string,mixed> $prepared @param array<string,mixed> $ftp */
    public function deployPrepared(array $prepared,array $ftp,string $packageDir): array
    {
        foreach(['host','username','password'] as $k) if(!isset($ftp[$k])||!is_string($ftp[$k])) return ['ok'=>false,'code'=>'EXPANSION_FTP_INPUT_INVALID'];
        try {
            $r=$this->ftp->deploy($packageDir,(string)$ftp['host'],(int)($ftp['port']??21),(string)$ftp['username'],(string)$ftp['password'],(string)($prepared['remote_web_root']??'/'),!empty($ftp['allow_plain_ftp']));
        } finally {
            $this->builder->destroy($packageDir);
        }
        if(empty($r['ok'])) return $r;
        $mark=$this->registry->markDeployed((string)$prepared['expansion_id']);
        return empty($mark['ok'])?$mark:$r+['expansion_id'=>$prepared['expansion_id']];
    }

    /** @param array<string,mixed> $prepared */
    public function deployPreparedLocal(array $prepared,string $localWebRoot,string $packageDir): array
    {
        $local=new KiComExpansionLocalFilesystemDeployer();
        try {
            $r=$local->deploy($packageDir,$localWebRoot);
        } finally {
            $this->builder->destroy($packageDir);
        }
        if(empty($r['ok'])) return $r;
        $mark=$this->registry->markDeployed((string)$prepared['expansion_id']);
        return empty($mark['ok'])?$mark:$r+['expansion_id'=>$prepared['expansion_id']];
    }

    /** @param array<string,mixed> $prepared */
    public function activatePrepared(array $prepared,KiComExpansionTransport $http): array
    {
        $id=(string)($prepared['expansion_id']??'');$childBase=rtrim((string)($prepared['child_base_url']??''),'/');
        if($id===''||$childBase==='') return ['ok'=>false,'code'=>'EXPANSION_PREPARED_INVALID'];
        $hello=$http->getJson($childBase.'/bootstrap.php?expansion_id='.rawurlencode($id));
        if(empty($hello['ok'])||($hello['code']??'')!=='CELL_ENROLLMENT_HELLO') return ['ok'=>false,'code'=>'EXPANSION_BOOTSTRAP_FAILED','detail'=>$hello['code']??'UNKNOWN'];
        $reach=$this->registry->markReachable($id); if(empty($reach['ok'])) return $reach;
        $accepted=$this->registry->enroll($id,(array)($hello['descriptor']??[]),(string)($hello['proof']??''));
        if(empty($accepted['ok'])) return $accepted;
        $cell=(array)$accepted['cell'];
        $activation=KiComExpansionProtocol::signEnvelope((string)$this->parent['cell_id'],(string)$cell['cell_id'],'EXPANSION_ACTIVATE',[
            'root_id'=>(string)$cell['root_id'],'parent_id'=>(string)$cell['parent_id'],'generation'=>(int)$cell['generation'],
        ],$this->parentSecret);
        $active=$http->postJson($childBase.'/federation.php',$activation);
        if(empty($active['ok'])||($active['code']??'')!=='CELL_ACTIVE'){
            $this->registry->revokeCell((string)$cell['cell_id'],'activation failed');
            return ['ok'=>false,'code'=>'EXPANSION_ACTIVATION_FAILED','detail'=>$active['code']??'UNKNOWN'];
        }
        $tick=KiComExpansionCronRelay::createTick($this->parent,$this->parentSecret,$cell,['reason'=>'activation-smoke']);
        $reply=$http->postJson($childBase.'/federation.php',$tick);
        $tickCheck=KiComExpansionCronRelay::verifyTickResult($reply,$this->parent,$cell);
        if(empty($tickCheck['ok'])) return ['ok'=>true,'code'=>'EXPANSION_ACTIVE_WITH_TICK_WARNING','cell'=>$cell,'tick'=>$tickCheck];
        return ['ok'=>true,'code'=>'EXPANSION_ACTIVE','cell'=>$cell,'tick'=>$tickCheck];
    }

    /** @param array<string,mixed> $ftp @return array<string,mixed> */
    public function expand(string $targetBaseUrl,array $ftp,string $remoteWebRoot='/',int $ttl=3600): array
    {
        $x=$this->preparePackage($targetBaseUrl,$remoteWebRoot,$ttl); if(empty($x['ok'])) return $x;
        $prep=(array)$x['prepared'];$pkg=(array)$x['package'];
        $d=$this->deployPrepared($prep,$ftp,(string)$pkg['directory']); if(empty($d['ok'])) return ['ok'=>false,'code'=>'EXPANSION_DEPLOY_FAILED','detail'=>$d];
        $http=new KiComExpansionHttpsTransport((string)$prep['child_base_url']);
        return $this->activatePrepared($prep,$http)+['deployment'=>$d];
    }

    /** @return array<string,mixed> */
    public function expandLocal(string $targetBaseUrl,string $localWebRoot,int $ttl=3600): array
    {
        $x=$this->preparePackage($targetBaseUrl,'/',$ttl); if(empty($x['ok'])) return $x;
        $prep=(array)$x['prepared'];$pkg=(array)$x['package'];
        $d=$this->deployPreparedLocal($prep,$localWebRoot,(string)$pkg['directory']); if(empty($d['ok'])) return ['ok'=>false,'code'=>'EXPANSION_DEPLOY_FAILED','detail'=>$d];
        $http=new KiComExpansionHttpsTransport((string)$prep['child_base_url']);
        return $this->activatePrepared($prep,$http)+['deployment'=>$d];
    }
}
