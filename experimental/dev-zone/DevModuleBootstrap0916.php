<?php
declare(strict_types=1);

/**
 * Fail-closed patch planner for the 0.9.15 -> 0.9.16 module bootstrap.
 *
 * It does not write files. It converts the exact live lib.php text into a
 * sequence of unique find/replace patches consumed by the existing isolated
 * DEV build primitives. Any unexpected source shape aborts the plan.
 */
final class KiComDevModuleBootstrap0916
{
    public const FROM_VERSION='0.9.15';
    public const TO_VERSION='0.9.16';

    /** @return array<string,mixed> */
    public static function plan(string $source): array
    {
        if(!preg_match("/const\\s+KICOM_VERSION\\s*=\\s*'([^']+)'\\s*;/",$source,$vm)) return ['ok'=>false,'code'=>'BOOTSTRAP_VERSION_NOT_FOUND'];
        if((string)$vm[1]!==self::FROM_VERSION) return ['ok'=>false,'code'=>'BOOTSTRAP_BASE_VERSION_MISMATCH','version'=>(string)$vm[1]];
        if(str_contains($source,'kicomLoadTrustedModules')||str_contains($source,"str_starts_with(\$path,'modules/')")||str_contains($source,'kicomArtifactTransportPullFallback')) return ['ok'=>false,'code'=>'BOOTSTRAP_ALREADY_PRESENT'];

        $patches=[];$working=$source;

        $living=self::matchUnique($working,"~^\\s*require_once\\s+__DIR__\\s*\\.\\s*'/living\\.php';\\s*$~m",'BOOTSTRAP_LIVING_REQUIRE_ANCHOR');
        if(empty($living['ok']))return $living;
        $find=(string)$living['match'];
        $replace=$find."\nkicomLoadTrustedModules();";
        $r=self::appendPatch($working,$patches,'module-loader-call',$find,$replace);if(empty($r['ok']))return $r;$working=(string)$r['source'];

        $selfUpdate=self::matchUnique($working,'~^function\\s+kicomSelfUpdateSupported\\(\\):\\s*bool\\s*\\{~m','BOOTSTRAP_SELFUPDATE_ANCHOR');
        if(empty($selfUpdate['ok']))return $selfUpdate;
        $find=(string)$selfUpdate['match'];
        $loader=<<<'PHP'
function kicomLoadTrustedModules(): void {
    $manifest=kicomBaseDir().'/genome/modules.json';
    if(!is_file($manifest))return;
    $raw=@file_get_contents($manifest);if($raw===false||strlen($raw)>65536)return;
    try{$cfg=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){return;}
    if(!is_array($cfg)||(int)($cfg['schema']??0)!==1||!is_array($cfg['modules']??null))return;
    $genome=kicomGenomeCurrent();if(!is_array($genome)||!is_array($genome['components']??null))return;
    $components=[];foreach($genome['components'] as $c){if(!is_array($c))continue;$p=(string)($c['path']??'');$sha=strtolower((string)($c['sha256']??''));if($p!==''&&preg_match('/^[a-f0-9]{64}$/',$sha))$components[$p]=$sha;}
    $moduleRoot=realpath(kicomBaseDir().'/modules');if($moduleRoot===false)return;$moduleRoot=rtrim(str_replace('\\','/',$moduleRoot),'/').'/';
    foreach($cfg['modules'] as $row){
        if(!is_array($row)||array_key_exists('enabled',$row)&&!$row['enabled'])continue;
        $path=kicomSafeUpdatePath((string)($row['path']??''));
        if($path===null||!str_starts_with($path,'modules/')||strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='php'||!isset($components[$path]))continue;
        $full=kicomBaseDir().'/'.$path;$real=realpath($full);if($real===false||!is_file($real))continue;$norm=str_replace('\\','/',$real);if(!str_starts_with($norm,$moduleRoot))continue;
        $actual=hash_file('sha256',$real)?:'';if($actual===''||!hash_equals((string)$components[$path],$actual))continue;
        require_once $real;
    }
}

PHP;
        $replace=$loader.$find;
        $r=self::appendPatch($working,$patches,'trusted-module-loader',$find,$replace);if(empty($r['ok']))return $r;$working=(string)$r['source'];

        $assetsPattern='~^\s*if\(str_starts_with\(\$path,\'assets/\'\)\)return[^\r\n]+$~m';
        $assets=self::matchUnique($working,$assetsPattern,'BOOTSTRAP_ASSETS_ALLOWLIST_ANCHOR');
        if(empty($assets['ok']))return $assets;
        $find=(string)$assets['match'];
        preg_match('/^(\\s*)/',$find,$indent);$sp=(string)($indent[1]??'    ');
        $moduleLine=$sp."if(str_starts_with(\$path,'modules/'))return basename(\$path)==='.htaccess'||in_array(strtolower(pathinfo(\$path,PATHINFO_EXTENSION)),['php','json','txt'],true);";
        $replace=$moduleLine."\n".$find;
        $r=self::appendPatch($working,$patches,'module-install-allowlist',$find,$replace);if(empty($r['ok']))return $r;$working=(string)$r['source'];

        $fallback=self::matchUnique($working,'~if\\(!\\$candidates\\)\\{\\s*\\$evo=kicomEvolutionAutonomousTick\\(\\);~','BOOTSTRAP_PULL_FALLBACK_ANCHOR');
        if(empty($fallback['ok']))return $fallback;
        $find=(string)$fallback['match'];
        $replace=<<<'PHP'
if(!$candidates){
        if(function_exists('kicomArtifactTransportPullFallback')){
            try{$artifactFallback=kicomArtifactTransportPullFallback($allowAuto);}catch(Throwable $e){$artifactFallback=['ok'=>false,'code'=>'ARTIFACT_FALLBACK_EXCEPTION'];}
            $artifactCode=(string)($artifactFallback['code']??'');
            if(in_array($artifactCode,['AUTO_INSTALLED_GREEN','STAGED_DECISION_REQUIRED'],true)){
                $now=['last_check_epoch'=>time(),'last_check_at'=>gmdate('c'),'feed_checks'=>$feedChecks,'last_code'=>'ARTIFACT_'.$artifactCode,'last_source'=>'artifact-transport','errors'=>$errors];kicomUpdateChannelStateWrite($now);
                return ['ok'=>true,'code'=>$now['last_code'],'errors'=>$errors,'feed_checks'=>$feedChecks,'artifact'=>$artifactFallback];
            }
            if(empty($artifactFallback['ok'])&&$artifactCode!==''&&$artifactCode!=='ARTIFACT_NO_UPDATE')$errors[]=['feed'=>'artifact-transport','code'=>$artifactCode];
        }
        $evo=kicomEvolutionAutonomousTick();
PHP;
        $r=self::appendPatch($working,$patches,'update-pull-module-fallback',$find,$replace);if(empty($r['ok']))return $r;$working=(string)$r['source'];

        return [
            'ok'=>true,
            'code'=>'BOOTSTRAP_PATCH_PLAN_READY',
            'from_version'=>self::FROM_VERSION,
            'to_version'=>self::TO_VERSION,
            'patches'=>$patches,
            'patch_count'=>count($patches),
            'input_sha256'=>hash('sha256',$source),
            'patched_sha256'=>hash('sha256',$working),
            'creates_production_pending'=>false,
            'installs_production'=>false,
        ];
    }

    /** @return array<string,mixed> */
    private static function matchUnique(string $source,string $pattern,string $code): array
    {
        $count=preg_match_all($pattern,$source,$all);
        if($count!==1||!isset($all[0][0]))return ['ok'=>false,'code'=>$code.'_MATCH_COUNT','matches'=>(int)$count];
        return ['ok'=>true,'match'=>(string)$all[0][0]];
    }

    /** @param list<array<string,string>> $patches @return array<string,mixed> */
    private static function appendPatch(string $source,array &$patches,string $label,string $find,string $replace): array
    {
        if($find===''||substr_count($source,$find)!==1)return ['ok'=>false,'code'=>'BOOTSTRAP_PATCH_MATCH_COUNT','label'=>$label,'matches'=>substr_count($source,$find)];
        $base=hash('sha256',$source);$next=str_replace($find,$replace,$source,$count);if($count!==1)return ['ok'=>false,'code'=>'BOOTSTRAP_PATCH_APPLY_FAILED','label'=>$label];
        $patches[]=['label'=>$label,'find'=>$find,'replace'=>$replace,'base_sha256'=>$base,'result_sha256'=>hash('sha256',$next)];
        return ['ok'=>true,'source'=>$next];
    }
}
