<?php
declare(strict_types=1);
/* KiCom Autonomous Immune Guardian 0.9.1.
 * This file is both a minimal preflight library and the secret-authenticated cron endpoint.
 */
if(!defined('KICOM_RECOVERY_EMBEDDED')) define('KICOM_RECOVERY_EMBEDDED', true);
require_once __DIR__.'/recovery.php';

const KICOM_IMMUNE_SCAN_INTERVAL = 10;
const KICOM_IMMUNE_MAINTENANCE_TTL = 900;

function kicomImmuneDir(): string { return rkLivingDir(); }
function kicomImmuneLockFile(): string { return kicomImmuneDir().'/immune.lock'; }
function kicomImmuneStateFile(): string { return kicomImmuneDir().'/immune_state.json'; }
function kicomImmuneMaintenanceFile(): string { return kicomImmuneDir().'/maintenance.json'; }

function kicomImmuneAtomicJson(string $file,array $row): bool {
    $json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    return $json!==false && rkAtomicWrite($file,$json."\n",0600);
}
function kicomImmuneReadJson(string $file): ?array {
    if(!is_file($file)) return null;
    $row=json_decode((string)@file_get_contents($file),true);
    return is_array($row)?$row:null;
}
function kicomImmuneLegacyPendingUpdateActive(): bool {
    /* Upgrade bridge for 0.9.0 -> 0.9.1: 0.9.0 has no maintenance lock yet, but its
       verified pending self-update remains present during the post-write healthcheck. */
    $pendingFile=rkVar().'/self_update/pending.json';
    if(!is_file($pendingFile)||!is_file(rkBase().'/lib.php')) return false;
    $pending=json_decode((string)@file_get_contents($pendingFile),true);
    if(!is_array($pending)||!is_string($pending['to_version']??null)) return false;
    $lib=(string)@file_get_contents(rkBase().'/lib.php');
    if(!preg_match("/const\s+KICOM_VERSION\s*=\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*;/",$lib,$m)) return false;
    return hash_equals((string)$pending['to_version'],(string)$m[1]);
}
function kicomImmuneMaintenanceActive(): bool {
    $row=kicomImmuneReadJson(kicomImmuneMaintenanceFile());
    if($row!==null){
        $expires=(int)($row['expires_at']??0);
        if($expires>0 && $expires<time()) @unlink(kicomImmuneMaintenanceFile());
        else return true;
    }
    return kicomImmuneLegacyPendingUpdateActive();
}
function kicomImmuneMaintenanceBegin(string $reason,string $targetVersion=''): bool {
    if(!rkEnsureDirs()) return false;
    return kicomImmuneAtomicJson(kicomImmuneMaintenanceFile(),[
        'active'=>true,'reason'=>$reason,'target_version'=>$targetVersion,
        'started_at'=>gmdate('c'),'expires_at'=>time()+KICOM_IMMUNE_MAINTENANCE_TTL
    ]);
}
function kicomImmuneMaintenanceEnd(): void { @unlink(kicomImmuneMaintenanceFile()); }

function kicomImmunePhpSyntaxCheck(): array {
    $lg=rkLoadTrustedGenome();
    if(empty($lg['ok'])||!is_array($lg['genome']??null)) return ['ok'=>false,'code'=>'TRUSTED_GENOME_UNAVAILABLE'];
    $bad=[];
    foreach(($lg['genome']['components']??[]) as $c){
        if(!is_array($c)) continue;
        $p=(string)($c['path']??'');
        if(!str_ends_with(strtolower($p),'.php')) continue;
        $raw=@file_get_contents(rkBase().'/'.$p);
        if($raw===false){$bad[]=['path'=>$p,'code'=>'MISSING'];continue;}
        try { token_get_all($raw,TOKEN_PARSE); }
        catch(ParseError $e){$bad[]=['path'=>$p,'code'=>'PHP_SYNTAX_INVALID'];}
    }
    return ['ok'=>count($bad)===0,'bad'=>$bad];
}

function kicomImmuneGuardianMaybe(bool $force=false,bool $quarantineUnknown=true): array {
    if(!rkEnsureDirs()) return ['ok'=>false,'code'=>'IMMUNE_STORAGE_UNAVAILABLE'];
    if(kicomImmuneMaintenanceActive()) return ['ok'=>true,'code'=>'MAINTENANCE_ACTIVE','skipped'=>true];

    $state=kicomImmuneReadJson(kicomImmuneStateFile())??[];
    $last=(int)($state['last_scan_epoch']??0);
    if(!$force && $last>0 && (time()-$last)<KICOM_IMMUNE_SCAN_INTERVAL)
        return ['ok'=>true,'code'=>'THROTTLED','skipped'=>true,'last_scan_epoch'=>$last];

    $fh=@fopen(kicomImmuneLockFile(),'c+');
    if(!$fh) return ['ok'=>false,'code'=>'IMMUNE_LOCK_OPEN_FAILED'];
    if(!@flock($fh,LOCK_EX|LOCK_NB)){@fclose($fh);return ['ok'=>true,'code'=>'IMMUNE_BUSY','skipped'=>true];}
    try {
        kicomImmuneAtomicJson(kicomImmuneStateFile(),[
            'last_scan_epoch'=>time(),'last_scan_at'=>gmdate('c'),'status'=>'scanning'
        ]);
        $scan=rkScan();
        if(empty($scan['ok'])) return ['ok'=>false,'code'=>(string)($scan['code']??'SCAN_FAILED'),'scan'=>$scan];
        if(empty($scan['trusted'])) return ['ok'=>true,'code'=>'GENOME_NOT_TRUSTED','skipped'=>true,'scan'=>$scan];
        if(empty($scan['lkg_ok'])) return ['ok'=>true,'code'=>'LKG_NOT_READY','skipped'=>true,'scan'=>$scan];
        if(!empty($scan['kernel_drift'])) {
            rkEvent('immune_kernel_drift','critical',['drift'=>$scan['drift']??[]]);
            return ['ok'=>false,'code'=>'KERNEL_DRIFT_REQUIRES_MANUAL_RECOVERY','scan'=>$scan];
        }

        $needsHeal=!empty($scan['drift']) || ($quarantineUnknown && !empty($scan['unknown']));
        $heal=null;
        if($needsHeal) $heal=rkHeal($quarantineUnknown);
        $after=$needsHeal ? (array)($heal['scan']??rkScan()) : $scan;
        $syntax=kicomImmunePhpSyntaxCheck();
        $healthy=!empty($after['healthy']) && !empty($syntax['ok']);

        if($needsHeal){
            rkEvent('guardian_auto_repair',$healthy?'info':'error',[
                'repaired'=>$heal['repaired']??[],
                'quarantined'=>$heal['quarantined']??[],
                'healthy'=>$healthy,
                'syntax_ok'=>(bool)($syntax['ok']??false)
            ]);
        }
        $row=[
            'last_scan_epoch'=>time(),'last_scan_at'=>gmdate('c'),
            'status'=>$healthy?'healthy':'degraded','healthy'=>$healthy,
            'drift_count'=>count($after['drift']??[]),'unknown_count'=>count($after['unknown']??[]),
            'auto_repair'=>$needsHeal,'syntax_ok'=>(bool)($syntax['ok']??false)
        ];
        kicomImmuneAtomicJson(kicomImmuneStateFile(),$row);
        return ['ok'=>$healthy,'code'=>$healthy?($needsHeal?'AUTO_HEALED':'HEALTHY'):'POST_HEAL_HEALTHCHECK_FAILED','scan'=>$after,'heal'=>$heal,'syntax'=>$syntax];
    } finally {
        @flock($fh,LOCK_UN);@fclose($fh);
    }
}


rkHeaders();
if(defined('KICOM_GUARDIAN_EMBEDDED')) return;
$cfgFile=rkLivingDir().'/guardian.json';$cfg=is_file($cfgFile)?json_decode((string)@file_get_contents($cfgFile),true):null;$key=(string)($_GET['key']??'');
if(!is_array($cfg)||!is_string($cfg['key_hash']??null)||$key===''||!hash_equals((string)$cfg['key_hash'],hash('sha256',$key))){http_response_code(404);exit;}
$r=kicomImmuneGuardianMaybe(true,true);header('Content-Type: text/plain; charset=utf-8');http_response_code($r['ok']?200:500);echo "KCL/1\n".($r['ok']?'OK':'ERROR')." guardian\nFACT code=".($r['code']??'UNKNOWN')."\nFACT healed=".count($r['heal']['repaired']??[])."\nFACT quarantined=".count($r['heal']['quarantined']??[])."\nFACT healthy=".(!empty($r['scan']['healthy'])?'true':'false')."\nEND\n";
