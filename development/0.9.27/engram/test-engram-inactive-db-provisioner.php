<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramInactiveDbProvisioner.php';

$checks=0;
function check58(bool $ok,string $message):void {
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL '.$message);
    ++$checks; echo "PASS ".$message."\n";
}
function reject58(callable $f,string $code,string $label):void {
    try{$f();}catch(RuntimeException $e){
        check58($e->getMessage()==='ENGRAM_PROVISION_'.$code,$label);
        return;
    }
    throw new RuntimeException('FAIL '.$label);
}
function clean58(string $p):void {
    if(is_link($p)||is_file($p)){@unlink($p);return;}
    if(!is_dir($p))return;
    foreach(scandir($p) as $n)if($n!=='.'&&$n!=='..')clean58($p.'/'.$n);
    @rmdir($p);
}
$base=sys_get_temp_dir().'/mirage-inactive-provision-'.bin2hex(random_bytes(7));
$web=$base.'/kicom';
$root=$base.'/engram-private';
$data=$root.'/data';
$owners=$root.'/owners';
$ownerFile=$owners.'/engram-owners.json';
$configFile=$root.'/engram-host.json';
$activation=$data.'/mirage-activation.sqlite';
$oauth=$data.'/mirage-oauth.sqlite';
$memory=$data.'/engrams.sqlite';
mkdir($base,0700);
mkdir($web,0755);
mkdir($root,0700);mkdir($data,0700);mkdir($owners,0700);
mkdir($root.'/backups',0700);
try {
    $sessionId=bin2hex(random_bytes(32));
    $csrf=bin2hex(random_bytes(24));
    $admin=['admin'=>true,'csrf'=>$csrf];
    $runtime=[
        'enabled'=>false,'operator_approved'=>false,
        'host_isolation_verified'=>false,'mcp_connector_enabled'=>false,
        'oauth_enabled'=>false,
        'web_root'=>$web,'data_dir'=>$data,'owner_registry'=>$ownerFile,
        'runtime_source'=>'setup-pending'
    ];
    file_put_contents($ownerFile,'{"schema":1,"owners":{}}');
    file_put_contents($configFile,json_encode(['schema'=>1]+$runtime,JSON_THROW_ON_ERROR));
    chmod($ownerFile,0600);chmod($configFile,0600);
    file_put_contents($memory,'synthetic PREEXISTING user engram file -- do not open');
    chmod($memory,0600);
    $originalMemory=hash_file('sha256',$memory);
    $originalConfig=hash_file('sha256',$configFile);
    $originalOwners=hash_file('sha256',$ownerFile);
    $prepare=static fn(array $currentSession,array $policy,string $submitted,
          string $confirmation,bool $https):array=>
        KiComEngramInactiveDbProvisioner::prepare(
            $web,$currentSession,$sessionId,$csrf,$submitted,
            $confirmation,$policy,$https
        );
    check58(KiComEngramInactiveDbProvisioner::loadInactiveRuntime($web)===$runtime,
        'trusted admin loader can read genuine setup-pending host config without activating it');
    $actualActive=$runtime;$actualActive['enabled']=true;
    file_put_contents($configFile,json_encode(['schema'=>1]+$actualActive,JSON_THROW_ON_ERROR));
    chmod($configFile,0600);
    reject58(fn()=>$prepare($admin,$runtime,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true),
        'INACTIVE_PRIVATE_SCAFFOLD_REQUIRED',
        'falsely inactive caller claim cannot override real active private host file');
    file_put_contents($configFile,json_encode(['schema'=>1]+$runtime,JSON_THROW_ON_ERROR));
    chmod($configFile,0600);
    reject58(fn()=>$prepare(['admin'=>false,'csrf'=>$csrf],$runtime,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true),
        'ADMIN_APPROVAL_REQUIRED','anonymous cannot initialize private DB');
    reject58(fn()=>$prepare($admin,$runtime,str_repeat('0',strlen($csrf)),
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true),
        'ADMIN_APPROVAL_REQUIRED','wrong CSRF denied');
    reject58(fn()=>$prepare($admin,$runtime,$csrf,'ja',true),
        'ADMIN_APPROVAL_REQUIRED','explicit exact human confirmation required');
    reject58(fn()=>$prepare($admin,$runtime,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',false),
        'ADMIN_APPROVAL_REQUIRED','unencrypted HTTP cannot initialize SQLite');
    check58(!is_file($activation)&&!is_file($oauth),
        'denied requests created no activation or OAuth SQLite file');
    $active=$runtime;$active['enabled']=true;
    reject58(fn()=>$prepare($admin,$active,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true),
        'INACTIVE_PRIVATE_SCAFFOLD_REQUIRED',
        'provisioner refuses to touch active private memory runtime');
    $spoof=$runtime;$spoof['web_root']='/tmp/foreign';
    reject58(fn()=>$prepare($admin,$spoof,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true),
        'INACTIVE_PRIVATE_SCAFFOLD_REQUIRED',
        'untrusted webroot refuses storage creation');
    chmod($data,0755);
    reject58(fn()=>$prepare($admin,$runtime,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true),
        'INACTIVE_PRIVATE_SCAFFOLD_REQUIRED',
        'insecure private directory blocks schema initialization');
    chmod($data,0700);
    $result=$prepare($admin,$runtime,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true);
    check58($result['ok']===true
        &&$result['activation']==='created_inactive'
        &&$result['oauth']==='created_inactive',
        'missing private activation and OAuth SQLite schemas created once');
    check58($result['memory_enabled']===false
        &&$result['mcp_enabled']===false
        &&$result['host_isolation_verified']===false,
        'new private SQLite schema does not activate Engram or claim host isolation');
    check58((fileperms($activation)&0077)===0
        &&(fileperms($oauth)&0077)===0,
        'new SQLite database files are mode 0600');
    $adb=new PDO('sqlite:'.$activation);
    check58($adb->query('SELECT state FROM activation_state WHERE singleton=1')->fetchColumn()==='inactive',
        'real activation schema initialized inactive');
    check58((int)$adb->query('SELECT count(*) FROM activation_nonces')->fetchColumn()===0,
        'no operator approval nonce forged during setup');
    $odb=new PDO('sqlite:'.$oauth);
    check58((int)$odb->query('SELECT count(*) FROM mirage_oauth_codes')->fetchColumn()===0
        &&(int)$odb->query('SELECT count(*) FROM mirage_oauth_tokens')->fetchColumn()===0,
        'no real token, grant, or authorization code created during setup');
    unset($adb,$odb);
    check58(hash_file('sha256',$memory)===$originalMemory
        &&hash_file('sha256',$configFile)===$originalConfig
        &&hash_file('sha256',$ownerFile)===$originalOwners,
        'existing Engram memory, private config and owner registry remain byte identical');

    $second=$prepare($admin,$runtime,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true);
    check58($second['activation']==='existing_inactive'
        &&$second['oauth']==='existing_inactive',
        'repeat authorized setup is strictly create-only and idempotent');

    $db=new PDO('sqlite:'.$activation);
    $db->exec("UPDATE activation_state SET state='active' WHERE singleton=1");
    unset($db);
    reject58(fn()=>$prepare($admin,$runtime,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true),
        'EXISTING_DATABASE_REQUIRES_REVIEW',
        'existing active database cannot be silently reset or overwritten');
    $db=new PDO('sqlite:'.$activation);
    $db->exec("UPDATE activation_state SET state='inactive' WHERE singleton=1");
    unset($db);

    $db=new PDO('sqlite:'.$oauth);
    $db->exec("INSERT INTO mirage_oauth_tokens
       (token_hash,client_id,connector_id,host_evidence_id,resource,scope,
        owner_binding,credential_fingerprint,issued_at,expires_at)
       VALUES('hash','client','connector','host','resource','scope','owner','fingerprint',1,2)");
    unset($db);
    reject58(fn()=>$prepare($admin,$runtime,$csrf,
        'INAKTIVE ENGRAM DATENBANKEN VORBEREITEN',true),
        'EXISTING_DATABASE_REQUIRES_REVIEW',
        'existing OAuth token database never cleared or reused by setup');
    check58(hash_file('sha256',$memory)===$originalMemory
       &&hash_file('sha256',$configFile)===$originalConfig
       &&hash_file('sha256',$ownerFile)===$originalOwners,
       'denied state-changing reruns preserve all preexisting private user files');
    echo "KICOM_ENGRAM_INACTIVE_PROVISION_TESTS_PASSED=$checks\n";
}finally{clean58($base);}
