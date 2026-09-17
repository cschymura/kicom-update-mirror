<?php
declare(strict_types=1);

/**
 * Narrow DEV binding for the first real Expansion Cell test and its managed
 * repair/upgrade paths.
 *
 * This adapter is intentionally fixed to the existing allowlisted KiCom
 * deployment resource `sandbox` and its HTTPS origin. It cannot select an
 * arbitrary filesystem path or production target.
 */
final class KiComDevExpansionBindings
{
    private const RESOURCE_ALIAS='sandbox';
    private const TARGET_BASE_URL='https://sandbox.rurtalbahn.info';
    private const EXPECTED_BUGGY_FEDERATION_SHA='d648b1b20c41f39049aa4cabd23ced3d9794cf2c1e92e602886b2e2674730653';

    private string $sourceDir;
    private string $parentBaseUrl;

    public function __construct(string $sourceDir,string $parentBaseUrl)
    {
        $this->sourceDir=rtrim($sourceDir,'/');
        $this->parentBaseUrl=rtrim($parentBaseUrl,'/');
        if(!is_file($this->sourceDir.'/ExpansionService.php')) throw new RuntimeException('DEV_EXPANSION_SOURCE_MISSING');
        $p=parse_url($this->parentBaseUrl);
        if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])) throw new RuntimeException('DEV_EXPANSION_PARENT_URL_INVALID');
        require_once $this->sourceDir.'/ExpansionService.php';
    }

    /** @return array<string,mixed> */
    public function resourceStatus(): array
    {
        $service=$this->service();
        $r=$service->deploymentResourceStatus(self::RESOURCE_ALIAS);
        if(empty($r['ok'])) return $r;
        return $r+['target_base_url'=>self::TARGET_BASE_URL];
    }

    /** @return array<string,mixed> */
    public function executeSandbox(): array
    {
        $service=$this->service();
        $ready=$service->deploymentResourceStatus(self::RESOURCE_ALIAS);
        if(empty($ready['ok'])) return $ready;
        $resource=(array)($ready['resource']??[]);
        if(($resource['class']??'')!=='test'||empty($resource['writable'])) return ['ok'=>false,'code'=>'DEV_EXPANSION_SANDBOX_NOT_READY'];

        return $service->execute([
            'target_base_url'=>self::TARGET_BASE_URL,
            'deploy_mode'=>'resource',
            'deployment_resource'=>self::RESOURCE_ALIAS,
            'ttl'=>3600,
        ]);
    }

    /**
     * Repair exactly the known first-child federation endpoint. The old hash is
     * bound here so this DEV action cannot silently overwrite an unexpected
     * target version.
     *
     * @return array<string,mixed>
     */
    public function repairSandboxFederation(): array
    {
        $service=$this->service();
        $ready=$service->deploymentResourceStatus(self::RESOURCE_ALIAS);
        if(empty($ready['ok'])) return $ready;
        $resource=(array)($ready['resource']??[]);
        if(($resource['class']??'')!=='test'||empty($resource['writable'])) return ['ok'=>false,'code'=>'DEV_EXPANSION_SANDBOX_NOT_READY'];

        return $service->repairFederationEndpoint(
            self::RESOURCE_ALIAS,
            self::TARGET_BASE_URL,
            self::EXPECTED_BUGGY_FEDERATION_SHA
        );
    }

    /**
     * Upgrade the already-active sandbox child to the current intrinsic Living
     * architecture. Identity, signing key and lineage stay unchanged. Existing
     * Living state is retained through a bounded snapshot before perception,
     * action memory and the situational world model are rebased.
     *
     * @return array<string,mixed>
     */
    public function upgradeSandboxLiving(): array
    {
        $service=$this->service();
        $ready=$service->deploymentResourceStatus(self::RESOURCE_ALIAS);
        if(empty($ready['ok'])) return $ready;
        $resource=(array)($ready['resource']??[]);
        if(($resource['class']??'')!=='test'||empty($resource['writable'])) return ['ok'=>false,'code'=>'DEV_EXPANSION_SANDBOX_NOT_READY'];

        $resolved=KiComExpansionKiComDeployTargetResolver::resolve(self::RESOURCE_ALIAS);
        if(!is_string($resolved)||$resolved==='') return ['ok'=>false,'code'=>'DEV_EXPANSION_SANDBOX_RESOLVE_FAILED'];
        $childBase=self::TARGET_BASE_URL.'/kicom';
        $http=new KiComExpansionHttpsTransport($childBase);
        $before=$http->getJson($childBase.'/status.php');
        $beforeCell=is_array($before['cell']??null)?(array)$before['cell']:[];
        if(empty($before['ok'])||($before['code']??'')!=='CELL_STATUS'||($beforeCell['state']??'')!=='active') return ['ok'=>false,'code'=>'DEV_EXPANSION_SANDBOX_CELL_NOT_ACTIVE'];
        if(!preg_match('/^cell-[a-f0-9]{24}$/',(string)($beforeCell['cell_id']??''))) return ['ok'=>false,'code'=>'DEV_EXPANSION_SANDBOX_CELL_ID_INVALID'];
        $beforeCellId=(string)$beforeCell['cell_id'];
        $beforePublic=(string)($beforeCell['public_key']??'');
        $beforeParent=(string)($beforeCell['parent_id']??'');
        $beforeRoot=(string)($beforeCell['root_id']??'');
        $beforeGeneration=(int)($beforeCell['generation']??0);

        $updater=new KiComExpansionManagedCellUpdater();
        $upgrade=$updater->upgradeLivingRuntime($resolved,$this->sourceDir,$childBase);
        if(empty($upgrade['ok'])) return $this->publicUpgradeResult($upgrade);

        $after=$http->getJson($childBase.'/status.php');
        $afterCell=is_array($after['cell']??null)?(array)$after['cell']:[];
        $identityOk=!empty($after['ok'])&&($after['code']??'')==='CELL_STATUS'&&($afterCell['state']??'')==='active'
            &&hash_equals($beforeCellId,(string)($afterCell['cell_id']??''))
            &&hash_equals($beforePublic,(string)($afterCell['public_key']??''))
            &&hash_equals($beforeParent,(string)($afterCell['parent_id']??''))
            &&hash_equals($beforeRoot,(string)($afterCell['root_id']??''))
            &&$beforeGeneration===(int)($afterCell['generation']??-1);
        $livingReady=!empty($afterCell['living_ready'])&&!empty($afterCell['living']['living_ready']);
        $paReady=!empty($afterCell['perception_action_ready'])&&!empty($afterCell['perception_action']['ready']);
        $perceptionState=(string)($afterCell['perception_action']['perception_state']??'UNKNOWN');
        $paShape=in_array($perceptionState,['AVAILABLE','DEGRADED','STALE'],true)
            &&(int)($afterCell['perception_action']['boundaries']??0)>0
            &&(int)($afterCell['perception_action']['actions']??0)>0;
        $world=is_array($afterCell['world_model']??null)?(array)$afterCell['world_model']:[];
        $worldState=(string)($world['knowledge_state']??'UNKNOWN');
        $worldReady=!empty($afterCell['world_model_ready'])&&!empty($world['ready'])
            &&in_array($worldState,['AVAILABLE','DEGRADED','STALE'],true)
            &&preg_match('/^[a-f0-9]{64}$/',(string)($world['world_id']??''))===1;
        if(!$identityOk||!$livingReady||!$paReady||!$paShape||!$worldReady){
            $rollback=!empty($upgrade['changed'])?$updater->rollbackLivingRuntime($resolved,$upgrade):['ok'=>true,'code'=>'NO_CHANGE'];
            return [
                'ok'=>false,
                'code'=>!empty($rollback['ok'])?'DEV_EXPANSION_LIVING_VERIFY_FAILED_ROLLED_BACK':'DEV_EXPANSION_LIVING_VERIFY_FAILED_ROLLBACK_FAILED',
                'identity_ok'=>$identityOk,'living_ready'=>$livingReady,'perception_action_ready'=>$paReady,'perception_state'=>$perceptionState,
                'world_model_ready'=>$worldReady,'world_model_state'=>$worldState,'rollback'=>$rollback,
            ];
        }

        // Reuse the signed federation smoke path. A successful tick refreshes
        // Perception/Action and the World Model and proves the parent as a peer.
        $tick=$service->repairFederationEndpoint(
            self::RESOURCE_ALIAS,
            self::TARGET_BASE_URL,
            self::EXPECTED_BUGGY_FEDERATION_SHA
        );
        if(empty($tick['ok'])){
            $rollback=!empty($upgrade['changed'])?$updater->rollbackLivingRuntime($resolved,$upgrade):['ok'=>true,'code'=>'NO_CHANGE'];
            return ['ok'=>false,'code'=>!empty($rollback['ok'])?'DEV_EXPANSION_LIVING_TICK_FAILED_ROLLED_BACK':'DEV_EXPANSION_LIVING_TICK_FAILED_ROLLBACK_FAILED','tick'=>$tick,'rollback'=>$rollback];
        }

        $afterTick=$http->getJson($childBase.'/status.php');
        $tickCell=is_array($afterTick['cell']??null)?(array)$afterTick['cell']:[];
        $tickPa=is_array($tickCell['perception_action']??null)?(array)$tickCell['perception_action']:[];
        $tickWorld=is_array($tickCell['world_model']??null)?(array)$tickCell['world_model']:[];
        $tickReady=!empty($afterTick['ok'])&&!empty($tickCell['perception_action_ready'])&&!empty($tickPa['ready'])
            &&(int)($tickPa['neighbors']??0)>=1
            &&!empty($tickCell['world_model_ready'])&&!empty($tickWorld['ready'])
            &&preg_match('/^[a-f0-9]{64}$/',(string)($tickWorld['world_id']??''))===1;
        if(!$tickReady){
            $rollback=!empty($upgrade['changed'])?$updater->rollbackLivingRuntime($resolved,$upgrade):['ok'=>true,'code'=>'NO_CHANGE'];
            return ['ok'=>false,'code'=>!empty($rollback['ok'])?'DEV_EXPANSION_WORLD_TICK_VERIFY_FAILED_ROLLED_BACK':'DEV_EXPANSION_WORLD_TICK_VERIFY_FAILED_ROLLBACK_FAILED','rollback'=>$rollback];
        }

        $public=$this->publicUpgradeResult($upgrade);
        return [
            'ok'=>true,
            'code'=>!empty($upgrade['changed'])?'EXPANSION_LIVING_UPGRADE_OK':'EXPANSION_LIVING_ALREADY_CURRENT',
            'cell'=>[
                'cell_id'=>$beforeCellId,
                'base_url'=>$childBase,
                'state'=>'active',
                'living_ready'=>true,
                'perception_action_ready'=>true,
                'world_model_ready'=>true,
                'living'=>$tickCell['living']??($afterCell['living']??null),
                'perception_action'=>$tickPa,
                'world_model'=>$tickWorld,
            ],
            'upgrade'=>$public,
            'federation_smoke'=>$tick,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicUpgradeResult(array $row): array
    {
        unset($row['_rollback']);
        return $row;
    }

    private function service(): KiComExpansionService
    {
        if(!function_exists('kicomVarDir')) throw new RuntimeException('DEV_EXPANSION_KICOM_RUNTIME_MISSING');
        return new KiComExpansionService(
            kicomVarDir().'/expansion_federation',
            $this->sourceDir,
            $this->parentBaseUrl
        );
    }
}