<?php
declare(strict_types=1);
// Synthetic dispatch-contract test: stubs verify dispatch and trusted binding,
// NOT a live OAuth server or actual SQLite token rotation.
final class KiComEngramOAuthTransactions{
    public static array $seen=[];
    public static function exchange(PDO $db,array $args,array $client,int $now):array{
        self::$seen=[$args,$client,$now];
        return ['grant'=>'authorization_code','scope'=>'engram.read'];
    }
}
final class KiComEngramOAuthContinuity{
    public static array $seen=[];
    public static function rotate(PDO $db,string $token,array $client,int $now):array{
        self::$seen=[$token,$client,$now];
        return ['grant'=>'refresh_token','scope'=>'engram.read'];
    }
}
require __DIR__.'/KiComEngramOAuthRefreshDispatch.php';
$n=0;
function ok(bool $value,string $label):void{
    global $n; if(!$value)throw new RuntimeException('FAIL '.$label);
    ++$n;echo "PASS $label\n";
}
function denied(callable $callback,string $label):void{
    try{$callback();}catch(RuntimeException $e){ok(true,$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
$pdo=(new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$client=[
    'client_id'=>'https://chatgpt.com/oauth/client.json',
    'connector_id'=>'mirage-engram',
    'host_evidence_id'=>'server-approved-host',
    'redirect_uri'=>'https://chatgpt.com/connector_platform_oauth_redirect',
];
$resource='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
$refresh=str_repeat('A',43);
$r='grant_type=refresh_token&refresh_token='.$refresh.'&client_id='.rawurlencode($client['client_id']).'&resource='.rawurlencode($resource);
$code='grant_type=authorization_code&code='.$refresh.'&code_verifier='.str_repeat('b',43)
.'&redirect_uri='.rawurlencode($client['redirect_uri'])
.'&client_id='.rawurlencode($client['client_id']).'&resource='.rawurlencode($resource);
$one=KiComEngramOAuthRefreshDispatch::afterOriginalHttpGate($pdo,$code,$client,10);
ok($one['grant']==='authorization_code','old authorization_code flows unchanged');
ok(KiComEngramOAuthTransactions::$seen[0]['client_id']===$client['client_id'],'original code exchange receives pinned client');
$two=KiComEngramOAuthRefreshDispatch::afterOriginalHttpGate($pdo,$r,$client,11);
ok($two['grant']==='refresh_token','read-only refresh dispatched');
ok(KiComEngramOAuthContinuity::$seen[1]===[
 'client_id'=>$client['client_id'],'connector_id'=>$client['connector_id'],
 'host_evidence_id'=>$client['host_evidence_id'],
],'all refresh bindings sourced from trusted runtime');
ok(KiComEngramOAuthContinuity::$seen[0]===$refresh,'only refresh token forwarded');
foreach(['client_id','connector_id','host_evidence_id','redirect_uri'] as $field){
  $bad=$client;unset($bad[$field]);
  denied(fn()=>KiComEngramOAuthRefreshDispatch::afterOriginalHttpGate($pdo,$r,$bad,12),'missing server '.$field.' rejected');
}
denied(fn()=>KiComEngramOAuthRefreshDispatch::afterOriginalHttpGate(
  $pdo,$r.'&scope=engram.write',$client,12),'requested write escalation rejected');
denied(fn()=>KiComEngramOAuthRefreshDispatch::afterOriginalHttpGate(
  $pdo,$r.'&owner=other',$client,12),'client owner claim rejected');
denied(fn()=>KiComEngramOAuthRefreshDispatch::afterOriginalHttpGate(
  $pdo,$r.'&refresh_token='.$refresh,$client,12),'refresh replay-looking duplicate rejected');
denied(fn()=>KiComEngramOAuthRefreshDispatch::afterOriginalHttpGate(
  $pdo,str_replace('https%3A%2F%2Fchatgpt.com%2Foauth%2Fclient.json','other',$r),$client,12),
  'client mismatch rejected');
echo "KICOM_DEV80_DISPATCH_ASSERTIONS=$n\n";
