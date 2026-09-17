<?php
declare(strict_types=1);
require_once __DIR__.'/DevModuleBootstrap0916.php';

function t(bool $ok,string $label): void { if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} echo "OK $label\n"; }

$fixture=<<<'PHP'
<?php
declare(strict_types=1);
const KICOM_VERSION = '0.9.15';
function kicomBaseDir(): string { return __DIR__; }
require_once __DIR__ . '/guardian.php';
require_once __DIR__ . '/living.php';
function kicomSelfUpdateSupported(): bool { return true; }
function kicomSelfUpdateInstallPathAllowed(string $path): bool {
    if(str_starts_with($path,'genome/'))return basename($path)==='.htaccess'||in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['json','sig','txt'],true);
    if(str_starts_with($path,'assets/'))return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['css','js','json','svg','png','jpg','jpeg','webp','ico','txt'],true);
    return false;
}
function kicomUpdatePullCheck(bool $allowAuto=true,bool $force=false): array {
    $candidates=[];$errors=[];$feedChecks=[];
    if(!$candidates){
        $evo=kicomEvolutionAutonomousTick();
        return ['ok'=>true,'evolution'=>$evo];
    }
    return ['ok'=>true];
}
PHP;

$p=KiComDevModuleBootstrap0916::plan($fixture);
t(!empty($p['ok'])&&($p['code']??'')==='BOOTSTRAP_PATCH_PLAN_READY','planner accepts expected 0.9.15 shape');
t(($p['patch_count']??0)===4,'planner emits four bounded patches');
t(($p['creates_production_pending']??true)===false&&($p['installs_production']??true)===false,'planner has no production action');

$working=$fixture;
foreach($p['patches'] as $patch){
    t(hash('sha256',$working)===$patch['base_sha256'],'patch base hash chained');
    t(substr_count($working,$patch['find'])===1,'patch anchor unique '.$patch['label']);
    $working=str_replace($patch['find'],$patch['replace'],$working,$count);t($count===1,'patch applies '.$patch['label']);
    t(hash('sha256',$working)===$patch['result_sha256'],'patch result hash chained');
}
t(str_contains($working,'kicomLoadTrustedModules();'),'trusted module loader call added');
t(str_contains($working,"str_starts_with(\$path,'modules/')"),'module install allowlist added');
t(str_contains($working,'kicomArtifactTransportPullFallback'),'pull fallback hook added');
try{token_get_all($working,TOKEN_PARSE);$syntax=true;}catch(ParseError $e){$syntax=false;}
t($syntax,'patched PHP parses');

$again=KiComDevModuleBootstrap0916::plan($working);
t(empty($again['ok'])&&($again['code']??'')==='BOOTSTRAP_ALREADY_PRESENT','planner refuses double application');
$wrong=str_replace("'0.9.15'","'0.9.14'",$fixture);
$bad=KiComDevModuleBootstrap0916::plan($wrong);
t(empty($bad['ok'])&&($bad['code']??'')==='BOOTSTRAP_BASE_VERSION_MISMATCH','planner refuses wrong base version');

echo "DEV_MODULE_BOOTSTRAP_SELFTEST_OK\n";
