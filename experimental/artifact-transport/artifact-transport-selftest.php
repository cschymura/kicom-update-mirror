<?php
declare(strict_types=1);
require_once __DIR__.'/ArtifactTransportBus.php';
require_once __DIR__.'/ArtifactFtpAdapter.php';

function tFail(string $m): never { fwrite(STDERR,"FAIL: $m\n"); exit(1); }
function tOk(bool $v,string $m): void { if(!$v)tFail($m); echo "OK: $m\n"; }
function tRm(string $dir): void { if(!is_dir($dir))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($dir); }

$base=sys_get_temp_dir().'/kicom-transport-'.bin2hex(random_bytes(4));
$store=$base.'/store';$inbox=$base.'/inbox';@mkdir($inbox,0700,true);
$filename='KiCom-9.9.9-test.zip';
$bytes="PK\x03\x04KICOM-TRANSPORT-SELFTEST";
$sha=hash('sha256',$bytes);
file_put_contents($inbox.'/'.$filename,$bytes);
$artifact=['version'=>'9.9.9','filename'=>$filename,'sha256'=>$sha];

try{
    $sources=[
        ['id'=>'fast-but-down','type'=>'external-adapter','adapter'=>'down','priority'=>10,'enabled'=>true],
        ['id'=>'local-inbox','type'=>'local-inbox','priority'=>20,'enabled'=>true],
        ['id'=>'manual-upload','type'=>'manual-upload','priority'=>900,'enabled'=>true],
        ['id'=>'recovery','type'=>'recovery','priority'=>999,'enabled'=>true],
    ];
    $adapters=['down'=>function(array $source,array $artifact,string $incoming):array{return ['ok'=>false,'code'=>'SIMULATED_FAST_SOURCE_DOWN'];}];
    $bus=new KiComArtifactTransportBus($store,$inbox,$sources,$adapters);
    $status=$bus->status();
    tOk(!empty($status['ok'])&&($status['authority']??'')==='transport-only','bus is transport-only');
    tOk(($status['production_tree_write']??true)===false,'bus has no production tree write authority');
    tOk(count($status['sources']??[])===4,'all fallback source classes visible');

    $acquired=$bus->acquire($artifact);
    tOk(!empty($acquired['ok'])&&($acquired['source_id']??'')==='local-inbox','slow local fallback used after fast source failed');
    tOk(count($acquired['attempts']??[])===2,'attempt chain stops at first exact successful artifact');
    tOk(is_file($inbox.'/'.$filename),'local inbox source is retained');
    tOk(hash_equals($sha,(string)($acquired['sha256']??'')),'acquired artifact hash verified');

    $after=$bus->status();$matrix=[];foreach($after['sources']??[] as $row)$matrix[$row['id']]=$row;
    tOk(($matrix['fast-but-down']['state']??'')===KiComArtifactTransportBus::STATE_UNAVAILABLE,'failed source remembered unavailable');
    tOk(($matrix['local-inbox']['state']??'')===KiComArtifactTransportBus::STATE_AVAILABLE,'successful fallback remembered available');
    $history=$store.'/state/history.jsonl';
    tOk(is_file($history)&&count(file($history,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[])>=2,'source experience history append-only');

    $bad=$bus->acquire(['version'=>'9.9.9','filename'=>$filename,'sha256'=>str_repeat('0',64)]);
    tOk(empty($bad['ok'])&&($bad['code']??'')==='ARTIFACT_ALL_AUTOMATIC_SOURCES_FAILED','hash mismatch cannot be bypassed by fallback');
    tOk(is_file($inbox.'/'.$filename),'rejected source bytes remain retained');

    $received=[];
    $receiver=function(string $path,string $name,string $source,bool $allowAuto) use (&$received,$sha): array {
        $received=['name'=>$name,'source'=>$source,'allow_auto'=>$allowAuto,'sha256'=>hash_file('sha256',$path)?:''];
        return ['ok'=>true,'code'=>'SIMULATED_KICOM_UPDATER_ACCEPTED','risk_class'=>'green'];
    };
    $bus2=new KiComArtifactTransportBus($base.'/handoff-store',$inbox,[['id'=>'local','type'=>'local-inbox','priority'=>1,'enabled'=>true]],[],$receiver);
    $handoff=$bus2->handoffToUpdater($artifact,true);
    tOk(!empty($handoff['ok'])&&($handoff['code']??'')==='SIMULATED_KICOM_UPDATER_ACCEPTED','verified artifact handed to existing updater callback');
    tOk(($received['sha256']??'')===$sha&&($received['name']??'')===$filename,'updater receives exact verified artifact');
    tOk(str_starts_with((string)($received['source']??''),'transport:'),'updater source identifies transport path without credentials');
    tOk(count(glob($base.'/handoff-store/archive/*/receipt.json')?:[])===1,'handoff artifact and receipt retained after use');
    tOk(is_file($inbox.'/'.$filename),'handoff never consumes manual inbox original');

    $externalBytes="PK\x03\x04EXTERNAL-ADAPTER";$externalSha=hash('sha256',$externalBytes);$externalArtifact=['version'=>'9.9.10','filename'=>'KiCom-9.9.10-test.zip','sha256'=>$externalSha];
    $writer=function(array $source,array $artifact,string $incoming) use ($externalBytes):array {
        $path=rtrim($incoming,'/').'/adapter-'.bin2hex(random_bytes(3)).'-'.$artifact['filename'];file_put_contents($path,$externalBytes);return ['ok'=>true,'code'=>'SIMULATED_FTPS_OK','path'=>$path];
    };
    $bus3=new KiComArtifactTransportBus($base.'/adapter-store',$base.'/empty-inbox',[['id'=>'ftps','type'=>'external-adapter','adapter'=>'ftp','mode'=>'ftps','priority'=>1,'enabled'=>true]],['ftp'=>$writer]);
    $external=$bus3->acquire($externalArtifact);
    tOk(!empty($external['ok'])&&($external['source_id']??'')==='ftps','external slow adapter can participate in same hash gate');

    $ftp=new KiComArtifactFtpAdapter(fn(array $source):array=>[]);
    $plain=$ftp(['mode'=>'ftp','allow_plaintext_ftp'=>false,'remote_path'=>'/{filename}'],$artifact,$base.'/ftp-incoming');
    tOk(empty($plain['ok'])&&($plain['code']??'')==='PLAINTEXT_FTP_FORBIDDEN','plaintext FTP is explicit break-glass only');

    echo "ARTIFACT TRANSPORT SELFTEST PASS\n";
}finally{tRm($base);}
