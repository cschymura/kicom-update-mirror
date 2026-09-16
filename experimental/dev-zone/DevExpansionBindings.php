<?php
declare(strict_types=1);

/**
 * Narrow DEV binding for the first real Expansion Cell test.
 *
 * This adapter is intentionally fixed to the existing allowlisted KiCom
 * deployment resource `sandbox` and its HTTPS origin. It cannot select an
 * arbitrary filesystem path or production target.
 */
final class KiComDevExpansionBindings
{
    private const RESOURCE_ALIAS='sandbox';
    private const TARGET_BASE_URL='https://sandbox.rurtalbahn.info';

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
