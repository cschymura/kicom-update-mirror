<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionParentIdentity.php';
require_once __DIR__.'/ExpansionRegistry.php';
require_once __DIR__.'/ExpansionCellPackageBuilder.php';
require_once __DIR__.'/ExpansionFtpDeployer.php';
require_once __DIR__.'/ExpansionOrchestrator.php';

/**
 * Parent-side command facade for KiCom expansion.
 *
 * FTP/FTPS credentials are accepted only in the execute() call and are not
 * written to persistent state or returned in results.
 */
final class KiComExpansionService
{
    private string $varDir;
    private string $sourceDir;
    private KiComExpansionParentIdentity $identity;

    public function __construct(string $varDir,string $sourceDir,string $parentBaseUrl)
    {
        $this->varDir=rtrim($varDir,'/');
        $this->sourceDir=rtrim($sourceDir,'/');
        if(!is_dir($this->varDir)&&!@mkdir($this->varDir,0700,true)&&!is_dir($this->varDir)) throw new RuntimeException('EXPANSION_STORAGE_UNAVAILABLE');
        @chmod($this->varDir,0700);
        $this->identity=new KiComExpansionParentIdentity($this->varDir.'/identity',$parentBaseUrl);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function execute(array $request): array
    {
        $target=trim((string)($request['target_base_url']??''));
        $remoteRoot=(string)($request['remote_web_root']??'/');
        $ftpIn=$request['ftp']??null;
        if($target===''||!is_array($ftpIn)) return ['ok'=>false,'code'=>'EXPANSION_COMMAND_INVALID'];
        foreach(['host','username','password'] as $key) if(!isset($ftpIn[$key])||!is_string($ftpIn[$key])||trim((string)$ftpIn[$key])==='') return ['ok'=>false,'code'=>'EXPANSION_FTP_INPUT_INVALID'];

        $ready=$this->identity->ensure();if(empty($ready['ok']))return $ready;
        $parent=(array)$ready['parent'];$secret=$this->identity->secretKeyB64();
        $registry=new KiComExpansionRegistry($this->varDir.'/registry',$parent);
        $builder=new KiComExpansionCellPackageBuilder($this->sourceDir);
        $deployer=new KiComExpansionFtpDeployer();
        $orchestrator=new KiComExpansionOrchestrator($registry,$builder,$deployer,$parent,$secret,$this->varDir.'/work');
        $ftp=[
            'host'=>(string)$ftpIn['host'],
            'port'=>(int)($ftpIn['port']??21),
            'username'=>(string)$ftpIn['username'],
            'password'=>(string)$ftpIn['password'],
            'allow_plain_ftp'=>!empty($ftpIn['allow_plain_ftp']),
        ];
        try {
            return $orchestrator->expand($target,$ftp,$remoteRoot,(int)($request['ttl']??3600));
        } finally {
            if(function_exists('sodium_memzero')){
                sodium_memzero($secret);
                sodium_memzero($ftp['password']);
            } else {
                $secret='';$ftp['password']='';
            }
            unset($request['ftp'],$ftpIn);
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $ready=$this->identity->ensure();if(empty($ready['ok']))return $ready;
        $parent=(array)$ready['parent'];$registry=new KiComExpansionRegistry($this->varDir.'/registry',$parent);
        return ['ok'=>true,'code'=>'EXPANSION_SERVICE_STATUS','parent'=>$parent,'cells'=>$registry->cells()];
    }
}
