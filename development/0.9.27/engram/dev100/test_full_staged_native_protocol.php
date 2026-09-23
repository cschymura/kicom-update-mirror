<?php
declare(strict_types=1);
/** Original-native staged MCP boundary smoke with *synthetic* PDO test double.
 * Usage: KICOM_STAGE_ROOT=/path/to/verified/nonproduction/native php this_file.php
 * This does NOT test original live OAuth/Passkey, real SQLite, or ChatGPT.
 */
$root=getenv('KICOM_STAGE_ROOT');
if(!is_string($root)||$root===''||!is_dir($root))throw new RuntimeException('NONPRODUCTION_NATIVE_STAGING_REQUIRED');
require_once rtrim($root,'/').'/modules/engram/KiComEngramMcpProtocol.php';
final class OriginalActivationStmt extends PDOStatement {
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array {
        return [['state'=>'active','owner_binding'=>$GLOBALS['ownerBinding'],'host_evidence_id'=>$GLOBALS['hostId']]];
    }
}
final class OriginalActivationDb extends PDO {
    public function __construct(){}
    public function getAttribute(int $attribute):mixed {return $attribute===PDO::ATTR_DRIVER_NAME?'sqlite':null;}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        if(!str_contains($query,'activation_state'))throw new RuntimeException('unexpected SQL');
        return new OriginalActivationStmt();
    }
}
$n=0;
function check100(bool $v,string $m):void {
    global $n;if(!$v)throw new RuntimeException('FAIL '.$m);
    ++$n;echo 'PASS '.$m."\n";
}
$fp=str_repeat('b',64);
$GLOBALS['ownerBinding']=hash('sha256',"mirage-owner\0".$fp);
$GLOBALS['hostId']=str_repeat('c',64);
$runtime=[
    'enabled'=>true,'operator_approved'=>true,'review_enabled'=>true,
    'mcp_connector_enabled'=>true,'host_isolation_verified'=>true,
    'operator_accepts_shared_host_risk'=>false,'runtime_source'=>'server-only-reviewed',
    'private_memory_scope'=>'dev-verified-owner','admin_subject'=>'mirage-owner',
    'expected_origin'=>'https://kicom.rurtalbahn.info','rp_id'=>'kicom.rurtalbahn.info',
    'mcp_connector_id'=>'mirage-engram','owner_binding'=>$GLOBALS['ownerBinding'],
    'host_evidence_id'=>$GLOBALS['hostId']
];
$connector=[
    'authenticated'=>true,'connector_id'=>'mirage-engram','credential_fingerprint'=>$fp,
    'owner_binding'=>$GLOBALS['ownerBinding'],'host_evidence_id'=>$GLOBALS['hostId']
];
$owner=static fn(string $fingerprint):array=>[
    'enabled'=>true,'subject'=>'mirage-owner','credential_fingerprint'=>$fingerprint,
    'namespaces'=>['project'],'engram_rights'=>['engram.read','engram.write']
];
$db=new OriginalActivationDb();
$factory=static function(array $identity):KiComEngramMcpJsonAdapter {
    throw new RuntimeException('read adapter must not run on listing');
};
$req=static fn($id,$method,$params)=>json_encode([
    'jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params
],JSON_THROW_ON_ERROR);
$call=static fn($wire,$write=null,$rt=null)=>KiComEngramMcpProtocol::handle(
    $wire,$rt??$runtime,$db,$owner,$connector,time(),$factory,$write
);
$read=$call($req(1,'tools/list',(object)[]));
$r=json_decode($read['body'],true,16,JSON_THROW_ON_ERROR);
check100($read['http_status']===200
    &&array_column($r['result']['tools'],'name')===['engram_search'],
    'original staged native MCP read-only host advertises search only');
$fakeWriter=static function(string $operation,array $input,string $idem,int $now):array {
    return ['id'=>str_repeat('a',32),'revision'=>1,
        'revision_hash'=>str_repeat('b',64),'state'=>'active'];
};
$write=$call($req(2,'tools/list',(object)[]),$fakeWriter);
$w=json_decode($write['body'],true,16,JSON_THROW_ON_ERROR);
check100(array_column($w['result']['tools'],'name')===[
    'engram_search','engram_write','engram_update','engram_archive'
], 'four native operator schemas appear only under server-injected writer');
$mutation=$req(3,'tools/call',['name'=>'engram_write','arguments'=>[
    'idempotency_key'=>'synthetic-001','input'=>['body'=>'synthetic only']
]]);
$denied=json_decode($call($mutation)['body'],true,16,JSON_THROW_ON_ERROR);
check100(($denied['error']['code']??null)===-32601,
    'read-only host refuses engram_write');
$ok=json_decode($call($mutation,$fakeWriter)['body'],true,16,JSON_THROW_ON_ERROR);
check100(($ok['result']['isError']??null)===false,
    'synthetic accepted write routes through server-injected callback');
$injected=$req(4,'tools/call',['name'=>'engram_write','arguments'=>[
    'idempotency_key'=>'synthetic-001','input'=>['body'=>'x'],'owner'=>'other'
]]);
$blocked=json_decode($call($injected,$fakeWriter)['body'],true,16,JSON_THROW_ON_ERROR);
check100(($blocked['error']['code']??null)===-32602,
    'untrusted MCP owner override rejected');
$inactive=$runtime;$inactive['mcp_connector_enabled']=false;
check100($call($req(5,'tools/list',(object)[]),$fakeWriter,$inactive)['http_status']===404,
    'inactive original host conceals all tool metadata even with writer');
echo "DEV100_ACTUAL_NATIVE_PROTOCOL_SMOKE=$n\n";
