<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionParentIdentity.php';
require_once __DIR__.'/ExpansionRegistry.php';
require_once __DIR__.'/ExpansionCellPackageBuilder.php';
require_once __DIR__.'/ExpansionFtpDeployer.php';
require_once __DIR__.'/ExpansionLocalFilesystemDeployer.php';
require_once __DIR__.'/ExpansionKiComDeployTargetResolver.php';
require_once __DIR__.'/ExpansionOrchestrator.php';

/**
 * Parent-side command facade for KiCom expansion.
 *
 * Supported deploy modes:
 * - resource: resolve an existing KiCom deployment resource alias internally;
 * - local: exact authorized webroot on the same hosting account;
 * - ftp/ftps: bootstrap transport for external webspaces.
 *
 * Transfer credentials and resolved local paths are never persisted or returned.
 */
final class KiComExpansionService
{
    private string $varDir;
    private string $sourceDir;
    private KiComExpansionParentIdentity $identity;
    /** @var callable(string):(?string)|null */
    private $deploymentResourceResolver;

    /** @param callable(string):(?string)|null $deploymentResourceResolver */
    public function __construct(string $varDir,string $sourceDir,string $parentBaseUrl,$deploymentResourceResolver=null)
    {
        $this->varDir=rtrim($varDir,'/');
        $this->sourceDir=rtrim($sourceDir,'/');
        if($deploymentResourceResolver!==null&&!is_callable($deploymentResourceResolver)) throw new InvalidArgumentException('EXPANSION_RESOURCE_RESOLVER_INVALID');
        if($deploymentResourceResolver===null&&KiComExpansionKiComDeployTargetResolver::available()){
            $deploymentResourceResolver=[KiComExpansionKiComDeployTargetResolver::class,'resolve'];
        }
        $this->deploymentResourceResolver=$deploymentResourceResolver;
        if(!is_dir($this->varDir)&&!@mkdir($this->varDir,0700,true)&&!is_dir($this->varDir)) throw new RuntimeException('EXPANSION_STORAGE_UNAVAILABLE');
        @chmod($this->varDir,0700);
        $this->identity=new KiComExpansionParentIdentity($this->varDir.'/identity',$parentBaseUrl);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function execute(array $request): array
    {
        $target=trim((string)($request['target_base_url']??''));
        $mode=strtolower(trim((string)($request['deploy_mode']??(isset($request['deployment_resource'])?'resource':(isset($request['local_web_root'])?'local':'ftps')))));
        if($target==='') return ['ok'=>false,'code'=>'EXPANSION_COMMAND_INVALID'];

        $ready=$this->identity->ensure();if(empty($ready['ok']))return $ready;
        $parent=(array)$ready['parent'];$secret=$this->identity->secretKeyB64();
        $registry=new KiComExpansionRegistry($this->varDir.'/registry',$parent);
        $builder=new KiComExpansionCellPackageBuilder($this->sourceDir);
        $deployer=new KiComExpansionFtpDeployer();
        $orchestrator=new KiComExpansionOrchestrator($registry,$builder,$deployer,$parent,$secret,$this->varDir.'/work');

        try {
            if($mode==='resource'){
                $name=strtolower(trim((string)($request['deployment_resource']??'')));
                if($name===''||!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/',$name)) return ['ok'=>false,'code'=>'EXPANSION_DEPLOYMENT_RESOURCE_INVALID'];
                if($this->deploymentResourceResolver===null) return ['ok'=>false,'code'=>'EXPANSION_DEPLOYMENT_RESOURCE_RESOLVER_UNAVAILABLE'];
                $resolved=($this->deploymentResourceResolver)($name);
                if(!is_string($resolved)||trim($resolved)==='') return ['ok'=>false,'code'=>'EXPANSION_DEPLOYMENT_RESOURCE_UNAVAILABLE'];

                // A previous local copy may already exist if the first HTTPS
                // bootstrap failed after deployment. Resume/repair only a
                // directory that carries KiCom's own Expansion Cell markers.
                if(is_dir(rtrim($resolved,'/').'/kicom')){
                    return $orchestrator->resumeExistingLocal($target,$resolved);
                }
                return $orchestrator->expandLocal($target,$resolved,(int)($request['ttl']??3600));
            }

            if($mode==='local'){
                $localRoot=trim((string)($request['local_web_root']??''));
                if($localRoot==='') return ['ok'=>false,'code'=>'EXPANSION_LOCAL_WEBROOT_REQUIRED'];
                if(is_dir(rtrim($localRoot,'/').'/kicom')) return $orchestrator->resumeExistingLocal($target,$localRoot);
                return $orchestrator->expandLocal($target,$localRoot,(int)($request['ttl']??3600));
            }

            if(!in_array($mode,['ftp','ftps'],true)) return ['ok'=>false,'code'=>'EXPANSION_DEPLOY_MODE_INVALID'];
            $remoteRoot=(string)($request['remote_web_root']??'/');
            $ftpIn=$request['ftp']??null;
            if(!is_array($ftpIn)) return ['ok'=>false,'code'=>'EXPANSION_FTP_INPUT_INVALID'];
            foreach(['host','username','password'] as $key) if(!isset($ftpIn[$key])||!is_string($ftpIn[$key])||trim((string)$ftpIn[$key])==='') return ['ok'=>false,'code'=>'EXPANSION_FTP_INPUT_INVALID'];
            $ftp=[
                'host'=>(string)$ftpIn['host'],
                'port'=>(int)($ftpIn['port']??21),
                'username'=>(string)$ftpIn['username'],
                'password'=>(string)$ftpIn['password'],
                'allow_plain_ftp'=>$mode==='ftp' || !empty($ftpIn['allow_plain_ftp']),
            ];
            try {
                return $orchestrator->expand($target,$ftp,$remoteRoot,(int)($request['ttl']??3600));
            } finally {
                if(function_exists('sodium_memzero')) sodium_memzero($ftp['password']); else $ftp['password']='';
                unset($request['ftp'],$ftpIn);
            }
        } finally {
            if(function_exists('sodium_memzero')) sodium_memzero($secret); else $secret='';
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $ready=$this->identity->ensure();if(empty($ready['ok']))return $ready;
        $parent=(array)$ready['parent'];$registry=new KiComExpansionRegistry($this->varDir.'/registry',$parent);
        return ['ok'=>true,'code'=>'EXPANSION_SERVICE_STATUS','parent'=>$parent,'cells'=>$registry->cells()];
    }

    /** @return array<string,mixed> */
    public function deploymentResourceStatus(string $alias): array
    {
        $alias=strtolower(trim($alias));
        if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/',$alias)) return ['ok'=>false,'code'=>'EXPANSION_DEPLOYMENT_RESOURCE_INVALID'];
        $row=KiComExpansionKiComDeployTargetResolver::publicDescriptor($alias);
        if($row===null) return ['ok'=>false,'code'=>'EXPANSION_DEPLOYMENT_RESOURCE_UNAVAILABLE'];
        return ['ok'=>true,'code'=>'EXPANSION_DEPLOYMENT_RESOURCE_READY','resource'=>$row];
    }
}
