<?php
declare(strict_types=1);
// Reproduces the original 0.9.37 PRIVATE OAuth schema, without HTTP/real secrets.
final class KiComEngramOAuthTransactions {
    public static function install(PDO $db):void {
        $db->exec('CREATE TABLE IF NOT EXISTS mirage_oauth_codes (
            code_hash TEXT PRIMARY KEY, request_hash TEXT NOT NULL UNIQUE,
            client_id TEXT NOT NULL, connector_id TEXT NOT NULL,
            host_evidence_id TEXT NOT NULL, redirect_uri TEXT NOT NULL,
            resource TEXT NOT NULL, state TEXT NOT NULL,
            pkce_challenge TEXT NOT NULL, owner_binding TEXT NOT NULL,
            admin_session_hash TEXT NOT NULL, issued_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL, consent_at INTEGER NOT NULL DEFAULT 0,
            approved_fingerprint TEXT NOT NULL DEFAULT "", consumed INTEGER NOT NULL DEFAULT 0)');
        $db->exec('CREATE TABLE IF NOT EXISTS mirage_oauth_tokens (
            token_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL,
            connector_id TEXT NOT NULL, host_evidence_id TEXT NOT NULL,
            resource TEXT NOT NULL, scope TEXT NOT NULL,
            owner_binding TEXT NOT NULL, credential_fingerprint TEXT NOT NULL,
            issued_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
            revoked INTEGER NOT NULL DEFAULT 0)');
    }
}
require __DIR__.'/KiComEngramOAuthAtomicExchange.php';
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function denied(callable $f,string $label):void{
    try {$f();} catch(Throwable $e){check(true,$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
function db():PDO{$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);return $db;}
$client=[
    'client_id'=>'https://chatgpt.com/oauth/client.json',
    'connector_id'=>'mirage-engram',
    'host_evidence_id'=>str_repeat('c',64),
    'redirect_uri'=>'https://chatgpt.com/connector_platform_oauth_redirect'
];
$resource='https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP';
$now=1790130000;
function seed(PDO $db,array $client,string $resource,int $now):array{
    $code=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    $verifier=str_repeat('v',43);
    $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $owner=str_repeat('a',64);$credential=str_repeat('b',64);
    $q=$db->prepare('INSERT INTO mirage_oauth_codes
      (code_hash,request_hash,client_id,connector_id,host_evidence_id,
      redirect_uri,resource,state,pkce_challenge,owner_binding,
      admin_session_hash,issued_at,expires_at,consent_at,approved_fingerprint,consumed)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,2)');
    $q->execute([hash('sha256',$code),hash('sha256','request-'.$code),
      $client['client_id'],$client['connector_id'],$client['host_evidence_id'],
      $client['redirect_uri'],$resource,str_repeat('s',24),$challenge,
      $owner,str_repeat('d',64),$now-10,$now+300,$now-1,$credential]);
    return [
      'request'=>['grant_type'=>'authorization_code','code'=>$code,
        'code_verifier'=>$verifier,'redirect_uri'=>$client['redirect_uri'],
        'client_id'=>$client['client_id'],'resource'=>$resource],
      'owner'=>$owner,'credential'=>$credential
    ];
}
$database=db();KiComEngramOAuthTransactions::install($database);
$p=seed($database,$client,$resource,$now);
denied(fn()=>KiComEngramOAuthAtomicExchange::redeem($database,$p['request'],$client,$now),
    'missing operator-installed refresh schema aborts entire exchange');
check((int)$database->query('SELECT consumed FROM mirage_oauth_codes')->fetchColumn()===2,'missing schema rolls back consumed code');
check((int)$database->query('SELECT COUNT(*) FROM mirage_oauth_tokens')->fetchColumn()===0,'missing schema rolls back access token');
KiComEngramOAuthAtomicExchange::prepareSchema($database);
KiComEngramOAuthAtomicExchange::prepareSchema($database);
check((int)$database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mirage_oauth_refresh_tokens'")->fetchColumn()===1,'explicit idempotent operator-only refresh preparation');
$result=KiComEngramOAuthAtomicExchange::redeem($database,$p['request'],$client,$now);
check($result['scope']==='engram.read' && isset($result['access_token'],$result['refresh_token']),'one atomic code redemption returns read access and initial refresh');
check($result['access_token']!==$result['refresh_token'],'access and refresh tokens are separate');
check((int)$database->query('SELECT consumed FROM mirage_oauth_codes')->fetchColumn()===1,'authorization code consumed once');
check((int)$database->query('SELECT COUNT(*) FROM mirage_oauth_tokens')->fetchColumn()===1,'one access token persisted');
check((int)$database->query('SELECT COUNT(*) FROM mirage_oauth_refresh_tokens')->fetchColumn()===1,'one refresh token persisted');
$q=$database->query('SELECT a.token_hash,a.owner_binding,a.credential_fingerprint,a.scope,
                            r.refresh_hash,r.owner_binding AS refresh_owner,
                            r.credential_fingerprint AS refresh_credential,
                            r.scope AS refresh_scope,r.parent_access_hash
                      FROM mirage_oauth_tokens a JOIN mirage_oauth_refresh_tokens r ON r.parent_access_hash=a.token_hash');
$stored=$q->fetch(PDO::FETCH_ASSOC);
check($stored['token_hash']===hash('sha256',$result['access_token'])
    && $stored['refresh_hash']===hash('sha256',$result['refresh_token']),'private store contains only token hashes');
check($stored['owner_binding']===$p['owner'] && $stored['refresh_owner']===$p['owner']
    && $stored['credential_fingerprint']===$p['credential']
    && $stored['refresh_credential']===$p['credential'],'owner and credential binding preserved');
check($stored['scope']==='engram.read'&&$stored['refresh_scope']==='engram.read','write never implicit');
denied(fn()=>KiComEngramOAuthAtomicExchange::redeem($database,$p['request'],$client,$now),'replay of consumed code denied');
check((int)$database->query('SELECT COUNT(*) FROM mirage_oauth_tokens')->fetchColumn()===1,'replay creates no new tokens');
function invalidCase(PDO $db,array $client,array $base,int $now,callable $change,string $label):void{
    $copy=seed($db,$client,'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP',$now);
    $q=$copy['request'];$cl=$client;$change($q,$cl);
    denied(fn()=>KiComEngramOAuthAtomicExchange::redeem($db,$q,$cl,$now),$label);
    $c=$db->prepare('SELECT consumed FROM mirage_oauth_codes WHERE code_hash=?');$c->execute([hash('sha256',$copy['request']['code'])]);
    check((int)$c->fetchColumn()===2,$label.' leaves original code redeemable');
}
invalidCase($database,$client,$p['request'],$now,fn(&$q,&$cl)=>$q['code_verifier']=str_repeat('z',43),'wrong PKCE');
invalidCase($database,$client,$p['request'],$now,fn(&$q,&$cl)=>$q['client_id']='https://chatgpt.com/oauth/foreign/client.json','foreign client');
invalidCase($database,$client,$p['request'],$now,fn(&$q,&$cl)=>$q['redirect_uri']='https://chatgpt.com/wrong','redirect mismatch');
invalidCase($database,$client,$p['request'],$now,fn(&$q,&$cl)=>$q['resource']='https://example.invalid','resource mismatch');
invalidCase($database,$client,$p['request'],$now,fn(&$q,&$cl)=>$q['scope']='engram.write','extra scope field');
invalidCase($database,$client,$p['request'],$now,fn(&$q,&$cl)=>$q['owner']='attacker','extra owner field');
invalidCase($database,$client,$p['request'],$now,fn(&$q,&$cl)=>$cl['connector_id']='another-connector','connector mismatch');
invalidCase($database,$client,$p['request'],$now,fn(&$q,&$cl)=>$cl['host_evidence_id']=str_repeat('d',64),'host mismatch');
check($database->query('PRAGMA quick_check')->fetchColumn()==='ok','SQLite integrity check');
echo "KICOM_DEV81_ASSERTIONS=$n\n";
