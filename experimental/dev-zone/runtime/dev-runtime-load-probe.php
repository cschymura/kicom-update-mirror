<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$result=['ok'=>false,'code'=>'DEV_RUNTIME_LOAD_PROBE','php_version'=>PHP_VERSION,'stages'=>[]];
function probeStage(string $name, callable $fn): bool {
    global $result;
    try {
        $value=$fn();
        $row=['stage'=>$name,'ok'=>true];
        if(is_array($value)) foreach($value as $k=>$v) if(in_array($k,['source','code','ok','scope'],true)&&is_scalar($v)) $row[$k]=$v;
        $result['stages'][]=$row;
        return true;
    } catch(Throwable $e) {
        $result['stages'][]=['stage'=>$name,'ok'=>false,'error_class'=>get_class($e)];
        return false;
    }
}
if(!probeStage('runtime_resolver',function(){require_once __DIR__.'/DevRuntimeRoot.php';return ['code'=>'LOADED'];})){echo json_encode($result),"\n";exit;}
if(!probeStage('core_require',function(){return kicomDevRequireCore();})){echo json_encode($result),"\n";exit;}
$last=end($result['stages']);
if(empty($last['ok'])||(($last['code']??'')==='DEV_CORE_LOAD_FAILED')){echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";exit;}
if(!probeStage('dev_session_require',function(){require_once __DIR__.'/DevSession.php';if(!class_exists('KiComDevSessionManager')) throw new RuntimeException('SESSION_CLASS_MISSING');return ['code'=>'LOADED'];})){echo json_encode($result),"\n";exit;}
if(!probeStage('artifact_importer_require',function(){require_once __DIR__.'/DevArtifactImporter.php';if(!class_exists('KiComDevArtifactImporter')) throw new RuntimeException('IMPORTER_CLASS_MISSING');return ['code'=>'LOADED'];})){echo json_encode($result),"\n";exit;}
if(!probeStage('artifact_importer_construct',function(){if(!function_exists('kicomVarDir')) throw new RuntimeException('KICOM_VAR_API_MISSING');$x=new KiComDevArtifactImporter(__DIR__,kicomVarDir().'/dev_artifacts');$s=$x->status();return is_array($s)?$s:['code'=>'STATUS_INVALID'];})){echo json_encode($result),"\n";exit;}
$result['ok']=true;$result['code']='DEV_RUNTIME_LOAD_OK';
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
