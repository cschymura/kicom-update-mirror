<?php
declare(strict_types=1);

const KICOM_VERSION='0.9.15';
$root=sys_get_temp_dir().'/kicom-artifact-runtime-'.bin2hex(random_bytes(4));
@mkdir($root,0700,true);
$GLOBALS['kicom_test_var']=$root;
$GLOBALS['kicom_receiver_calls']=[];
function kicomVarDir(): string { return (string)$GLOBALS['kicom_test_var']; }
function kicomUpdateChannelsLoad(): array { return ['pull'=>['feeds'=>[]]]; }
function kicomReceiveSelfUpdatePackage(string $path,string $name,string $source,bool $allowAuto=true): array {
    $GLOBALS['kicom_receiver_calls'][]=['path'=>$path,'name'=>$name,'source'=>$source,'allow_auto'=>$allowAuto,'sha256'=>hash_file('sha256',$path)?:''];
    return ['ok'=>true,'code'=>'STAGED_DECISION_REQUIRED','risk_class'=>'yellow'];
}
require_once __DIR__.'/ArtifactRuntimeModule.php';

function t(bool $ok,string $label): void { if(!$ok){fwrite(STDERR,"FAIL $label\n");cleanup();exit(1);} echo "OK $label\n"; }
function cleanup(): void {
    $root=(string)($GLOBALS['kicom_test_var']??'');if($root===''||!is_dir($root))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($root);
}

$status=KiComArtifactRuntimeModule::status();
t(!empty($status['ok'])&&($status['authority']??'')==='transport-only','runtime remains transport-only');
t(($status['duplicates_installer']??true)===false,'runtime does not duplicate installer');
$types=array_column($status['sources']??[],'type');
t(in_array('local-inbox',$types,true)&&in_array('manual-upload',$types,true)&&in_array('recovery',$types,true),'emergency source classes retained');

$inbox=$root.'/artifact_transport/inbox';@mkdir($inbox,0700,true);
$bytes="PK\x03\x04offline-test-artifact";$filename='KiCom-0.9.16.zip';$sha=hash('sha256',$bytes);
file_put_contents($inbox.'/'.$filename,$bytes,LOCK_EX);
file_put_contents($inbox.'/artifact.json',json_encode(['version'=>'0.9.16','filename'=>$filename,'sha256'=>$sha],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
$r=KiComArtifactRuntimeModule::fallbackPull(true);
t(!empty($r['ok'])&&($r['code']??'')==='STAGED_DECISION_REQUIRED','offline descriptor reaches existing updater');
t(($r['discovery_code']??'')==='ARTIFACT_OFFLINE_DESCRIPTOR_SELECTED','offline descriptor chosen without online discovery');
t(count($GLOBALS['kicom_receiver_calls'])===1,'existing receiver called exactly once');
$call=$GLOBALS['kicom_receiver_calls'][0];
t(($call['name']??'')===$filename&&($call['sha256']??'')===$sha,'receiver gets exact hash-bound bytes');
t(str_contains((string)($call['source']??''),'artifact-transport'),'handoff source is transport-labelled');
$archives=glob($root.'/artifact_transport/archive/*/receipt.json')?:[];
t(count($archives)===1,'handoff evidence archived append-only');
t(is_file($inbox.'/'.$filename)&&is_file($inbox.'/artifact.json'),'offline source evidence is not deleted');

cleanup();
echo "ARTIFACT_RUNTIME_SELFTEST_OK\n";
