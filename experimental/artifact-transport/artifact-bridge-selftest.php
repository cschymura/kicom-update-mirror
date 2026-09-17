<?php
declare(strict_types=1);
$GLOBALS['bridge_capture']=[];
function kicomReceiveSelfUpdatePackage(string $path,string $name,string $source,bool $allowAuto=true): array {
    $GLOBALS['bridge_capture']=['path'=>$path,'name'=>$name,'source'=>$source,'allow_auto'=>$allowAuto,'sha256'=>hash_file('sha256',$path)?:''];
    return ['ok'=>true,'code'=>'STUB_UPDATER_ACCEPTED','risk_class'=>'green'];
}
require_once __DIR__.'/ArtifactKiComBridge.php';
function bFail(string $m): never { fwrite(STDERR,"FAIL: $m\n"); exit(1); }
function bOk(bool $v,string $m): void { if(!$v)bFail($m); echo "OK: $m\n"; }

$file=sys_get_temp_dir().'/kicom-bridge-'.bin2hex(random_bytes(4)).'.zip';
file_put_contents($file,"PK\x03\x04BRIDGE-SELFTEST");
try{
    $status=KiComArtifactRuntimeBridge::status();
    bOk(!empty($status['ok'])&&($status['duplicates_installer']??true)===false,'bridge binds existing updater without duplicating installer');
    $receiver=KiComArtifactRuntimeBridge::receiver();
    $r=$receiver($file,'KiCom-9.9.9.zip','transport:local-inbox',true);
    bOk(!empty($r['ok'])&&($r['code']??'')==='STUB_UPDATER_ACCEPTED','bridge invokes existing updater receiver');
    $c=$GLOBALS['bridge_capture'];
    bOk(($c['name']??'')==='KiCom-9.9.9.zip'&&($c['allow_auto']??false)===true,'bridge preserves package name and updater auto policy');
    bOk(str_starts_with((string)($c['source']??''),'artifact-transport:'),'bridge labels transport source');
    $missing=$receiver($file.'.missing','KiCom-9.9.9.zip','local',true);
    bOk(empty($missing['ok'])&&($missing['code']??'')==='ARTIFACT_KICOM_INPUT_MISSING','missing transport input rejected before updater');
    echo "ARTIFACT BRIDGE SELFTEST PASS\n";
}finally{@unlink($file);}
