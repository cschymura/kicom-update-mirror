<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramMcpJsonAdapter.php';
require __DIR__.'/KiComEngramMcpRuntimeGate.php';

$checks=0;
function check44(bool $yes,string $name):void{
    global $checks;
    if(!$yes)throw new RuntimeException('FAIL '.$name);
    ++$checks; echo "PASS $name\n";
}
function deny44(callable $run,string $name):void{
    try{$run();}catch(RuntimeException $e){
        check44($e->getMessage()==='MCP_RUNTIME_INACTIVE_OR_UNAUTHORIZED',$name);
        return;
    }
    throw new RuntimeException('FAIL '.$name);
}
function clean44(string $p):void{
    if(is_link($p)||is_file($p)){@unlink($p);return;}
    if(!is_dir($p))return;
    foreach(scandir($p) as $f)if($f!=='.'&&$f!=='..')clean44($p.'/'.$f);
    @rmdir($p);
}

$root=sys_get_temp_dir().'/mirage-gate-'.bin2hex(random_bytes(8));
$web=$root.'/web';$private=$root.'/private';
mkdir($root,0700);mkdir($web,0755);mkdir($private,0700);
try {
    $store=new KiComEngramStore($private,$web);
    $created=$store->create('mirage-owner','project','technical',
        'Synthetic platform green train for gate test only.',
        'synthetic_test','mcp://synthetic-runtime-gate');
    check44(($created['revision']??null)===1,'initial fixture exists in synthetic private store');

    $fp=hash('sha256','synthetic-fingerprint-not-a-real-passkey');
    $binding=hash('sha256',"mirage-owner\0".$fp);
    $hostId=hash('sha256','synthetic-host-evidence');
    $runtime=[
      'enabled'=>true,'operator_approved'=>true,'host_isolation_verified'=>true,
      'review_enabled'=>true,'mcp_connector_enabled'=>true,
      'runtime_source'=>'server-only-reviewed',
      'private_memory_scope'=>'dev-verified-owner',
      'admin_subject'=>'mirage-owner',
      'expected_origin'=>'https://kicom.rurtalbahn.info',
      'rp_id'=>'kicom.rurtalbahn.info',
      'mcp_connector_id'=>'chatgpt-mirage-test',
      'owner_binding'=>$binding,'host_evidence_id'=>$hostId
    ];
    $identity=[
      'authenticated'=>true,'connector_id'=>'chatgpt-mirage-test',
      'credential_fingerprint'=>$fp,'owner_binding'=>$binding,
      'host_evidence_id'=>$hostId
    ];
    $owner=[
      'enabled'=>true,'credential_fingerprint'=>$fp,'subject'=>'mirage-owner',
      'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']
    ];
    $lookup=static function(string $fingerprint)use($fp,$owner):?array {
        return $fingerprint===$fp?$owner:null;
    };
    $activation=new PDO('sqlite::memory:');
    $activation->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $activation->exec('CREATE TABLE activation_state(singleton INTEGER PRIMARY KEY, state TEXT, owner_binding TEXT, host_evidence_id TEXT)');
    $insert=$activation->prepare('INSERT INTO activation_state(singleton,state,owner_binding,host_evidence_id) VALUES(1,?,?,?)');
    $insert->execute(['active',$binding,$hostId]);
    $nonceDb=new PDO('sqlite::memory:');
    $factoryCalls=0;
    $factory=static function(array $serverIdentity)use(&$factoryCalls,$store,$nonceDb):KiComEngramMcpJsonAdapter {
        ++$factoryCalls;
        if(($serverIdentity['owner']??null)!=='mirage-owner')throw new RuntimeException('unexpected owner');
        $contract=new KiComEngramMcpContract($nonceDb,'mirage-owner');
        return new KiComEngramMcpJsonAdapter(
            new KiComEngramMcpController($store,$contract,'mirage-owner','active')
        );
    };
    $now=1800000300;
    $request=[
      'v'=>1,'op'=>'read','owner'=>'mirage-owner','namespace'=>'project',
      'nonce'=>'gate-001','issued_at'=>$now,'limit'=>2,'query'=>'platform green'
    ];
    $wire=json_encode($request,JSON_THROW_ON_ERROR);
    $send=static fn(
        string $body,array $policy,array $who,callable $registry,PDO $db,callable $make
    ):array=>json_decode(KiComEngramMcpRuntimeGate::dispatch(
        $body,$policy,$db,$registry,$who,$now,$make),true,16,JSON_THROW_ON_ERROR);
    $denied=['ok'=>false,'error'=>'REQUEST_DENIED'];
    $authorized=KiComEngramMcpRuntimeGate::authorize($runtime,$activation,$lookup,$identity);
    check44($authorized===['authenticated'=>true,'owner'=>'mirage-owner','connector_id'=>'chatgpt-mirage-test'],
        'server identity resolved solely from verified policy and current registry');
    $reply=$send($wire,$runtime,$identity,$lookup,$activation,$factory);
    check44(($reply['ok']??null)===true&&($reply['result']['count']??null)===1,
        'explicitly active synthetic host can perform bounded read');
    check44(($reply['result']['items'][0]['body']??null)==='Synthetic platform green train for gate test only.',
        'owner-scoped minimal projection reaches synthetic private store');
    check44($factoryCalls===1,'adapter factory invoked only after all authentication gates');

    $inactive=$runtime;$inactive['enabled']=false;
    $count=$factoryCalls;
    check44($send($wire,$inactive,$identity,$lookup,$activation,$factory)===$denied
        && $factoryCalls===$count,'inactive host refuses access before constructing adapter');
    $scaffold=$runtime;
    foreach(['enabled','operator_approved','host_isolation_verified','review_enabled','mcp_connector_enabled']as $flag)$scaffold[$flag]=false;
    $scaffold['runtime_source']='setup-pending';
    $scaffold['private_memory_scope']='setup-pending';
    unset($scaffold['mcp_connector_id'],$scaffold['owner_binding'],$scaffold['host_evidence_id']);
    check44($send($wire,$scaffold,$identity,$lookup,$activation,$factory)===$denied
        && $factoryCalls===$count,'actual 0.9.29-style inactive scaffold denies read with no store access');

    foreach(['operator_approved','host_isolation_verified','review_enabled','mcp_connector_enabled'] as $flag){
        $p=$runtime;$p[$flag]=false;
        check44($send($wire,$p,$identity,$lookup,$activation,$factory)===$denied
            && $factoryCalls===$count,'disabled '.$flag.' denies before factory');
    }
    $p=$runtime;$p['runtime_source']='setup-pending';
    check44($send($wire,$p,$identity,$lookup,$activation,$factory)===$denied,
        'unreviewed runtime source denied');
    $p=$runtime;$p['private_memory_scope']='setup-pending';
    check44($send($wire,$p,$identity,$lookup,$activation,$factory)===$denied,
        'unreviewed private memory scope denied');
    $p=$runtime;$p['admin_subject']='foreign';
    check44($send($wire,$p,$identity,$lookup,$activation,$factory)===$denied,
        'foreign owner policy denied');
    $p=$runtime;$p['mcp_connector_id']='other-connector';
    check44($send($wire,$p,$identity,$lookup,$activation,$factory)===$denied,
        'connector-id mismatch denied');
    $p=$runtime;$p['host_evidence_id']=hash('sha256','foreign-host');
    check44($send($wire,$p,$identity,$lookup,$activation,$factory)===$denied,
        'host-evidence mismatch denied');

    $who=$identity;$who['authenticated']=false;
    check44($send($wire,$runtime,$who,$lookup,$activation,$factory)===$denied,
        'unauthenticated connector denied');
    $who=$identity;$who['connector_id']='other-connector';
    check44($send($wire,$runtime,$who,$lookup,$activation,$factory)===$denied,
        'wrong verified connector denied');
    $who=$identity;$who['credential_fingerprint']=hash('sha256','foreign-key');
    check44($send($wire,$runtime,$who,$lookup,$activation,$factory)===$denied,
        'foreign passkey identity cannot cross owner boundary');
    $who=$identity;$who['owner_binding']=hash('sha256','other-owner');
    check44($send($wire,$runtime,$who,$lookup,$activation,$factory)===$denied,
        'wrong connector owner binding denied');
    $who=$identity;$who['token']='injected';
    check44($send($wire,$runtime,$who,$lookup,$activation,$factory)===$denied,
        'identity injection fields fail closed');

    $revoked=$owner;$revoked['enabled']=false;
    $lookupRevoked=static fn(string $k):?array=>$k===$fp?$revoked:null;
    check44($send($wire,$runtime,$identity,$lookupRevoked,$activation,$factory)===$denied,
        'revoked passkey denied at request time');
    $lookupMissing=static fn(string $k):?array=>null;
    check44($send($wire,$runtime,$identity,$lookupMissing,$activation,$factory)===$denied,
        'missing owner registry entry denied');
    $foreignOwner=$owner;$foreignOwner['subject']='foreign';
    $lookupForeign=static fn(string $k):?array=>$foreignOwner;
    check44($send($wire,$runtime,$identity,$lookupForeign,$activation,$factory)===$denied,
        'foreign registry owner denied');
    $badRights=$owner;$badRights['engram_rights']=['engram.write'];
    $lookupNoRead=static fn(string $k):?array=>$badRights;
    check44($send($wire,$runtime,$identity,$lookupNoRead,$activation,$factory)===$denied,
        'owner with write-only rights cannot read');
    $badSpace=$owner;$badSpace['namespaces']=['other'];
    $lookupNoSpace=static fn(string $k):?array=>$badSpace;
    check44($send($wire,$runtime,$identity,$lookupNoSpace,$activation,$factory)===$denied,
        'owner namespace must match known private scope');
    $lookupError=static function(string $k):?array{throw new RuntimeException('private registry path must not leak');};
    check44($send($wire,$runtime,$identity,$lookupError,$activation,$factory)===$denied,
        'registry failure denies with minimized error');

    $activation->exec("UPDATE activation_state SET state='inactive' WHERE singleton=1");
    check44($send($wire,$runtime,$identity,$lookup,$activation,$factory)===$denied,
        'inactive activation transaction denies');
    $activation->exec("UPDATE activation_state SET state='pending' WHERE singleton=1");
    check44($send($wire,$runtime,$identity,$lookup,$activation,$factory)===$denied,
        'pending activation transaction denies');
    $activation->exec("UPDATE activation_state SET state='active',host_evidence_id='".hash('sha256','foreign-evidence')."' WHERE singleton=1");
    check44($send($wire,$runtime,$identity,$lookup,$activation,$factory)===$denied,
        'foreign activation host binding denied');
    $activation->exec("UPDATE activation_state SET host_evidence_id='".$hostId."',owner_binding='".hash('sha256','foreign-owner')."' WHERE singleton=1");
    check44($send($wire,$runtime,$identity,$lookup,$activation,$factory)===$denied,
        'foreign activation owner binding denied');
    $activation->exec("UPDATE activation_state SET owner_binding='".$binding."' WHERE singleton=1");

    $noTable=new PDO('sqlite::memory:');
    check44($send($wire,$runtime,$identity,$lookup,$noTable,$factory)===$denied,
        'missing activation table fails closed with no auto-create');

    $count=$factoryCalls;
    $bad=$request;$bad['op']='append';$bad['nonce']='gate-002';$bad['query']='unsolicited write';
    check44($send(json_encode($bad),$runtime,$identity,$lookup,$activation,$factory)===$denied
        && $factoryCalls===$count,'MCP append unavailable until one-time human consent is integrated');
    $bad=$request;$bad['namespace']='other';$bad['nonce']='gate-003';
    check44($send(json_encode($bad),$runtime,$identity,$lookup,$activation,$factory)===$denied
        && $factoryCalls===$count,'foreign namespace denied before adapter');
    check44($send('{bad',$runtime,$identity,$lookup,$activation,$factory)===$denied
        && $factoryCalls===$count,'malformed JSON denied without constructing adapter');
    check44($send(str_repeat('x',2049),$runtime,$identity,$lookup,$activation,$factory)===$denied
        && $factoryCalls===$count,'oversized request denied without constructing adapter');
    check44($send('[]',$runtime,$identity,$lookup,$activation,$factory)===$denied
        && $factoryCalls===$count,'list input denied');
    $bad=$request;$bad['nonce']='gate-004';$bad['owner']='foreign';
    check44($send(json_encode($bad),$runtime,$identity,$lookup,$activation,$factory)===$denied,
        'owner spoof in request denied by inner contract');
    $bad=$request;$bad['nonce']='gate-005';$bad['query']='platform';
    $second=$send(json_encode($bad),$runtime,$identity,$lookup,$activation,$factory);
    check44(($second['ok']??null)===true&&($second['result']['count']??null)===1,
        'fresh request reads existing synthetic memory');
    check44($send(json_encode($bad),$runtime,$identity,$lookup,$activation,$factory)===$denied,
        'inner nonce replay denied through minimized outer transport');
    check44(count($store->search('mirage-owner','project','unsolicited write'))===0,
        'blocked append never inserts an unsolicited record');
    check44(($store->health()['quick_check']??null)==='ok',
        'SQLite synthetic store remains healthy');
    echo "KICOM_ENGRAM_MCP_RUNTIME_GATE_TESTS_PASSED=$checks\n";
}finally{
    unset($store,$activation,$nonceDb);
    clean44($root);
}
