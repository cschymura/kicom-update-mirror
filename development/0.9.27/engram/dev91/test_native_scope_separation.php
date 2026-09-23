<?php
declare(strict_types=1);
/** Run against the exact native 0.9.37 OAuthTransactions source with DEV91 patch.
 * PDO test doubles ONLY: does not prove real SQLite, consent issuance, HTTP or live MCP. */
$path=getenv('KICOM_DEV91_NATIVE_FILE');
if(!is_string($path)||$path===''||!is_file($path))throw new RuntimeException('Exact staged native OAuthTransactions source required');
require $path;
final class NativeScopeTestStatement extends PDOStatement {
 public function __construct(private mixed $row){}
 public function execute(?array $params=null):bool{return true;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->row;}
}
final class NativeScopeTestDatabase extends PDO {
 public function __construct(public mixed $row){}
 public function getAttribute(int $attribute):mixed{return $attribute===PDO::ATTR_DRIVER_NAME?'sqlite':null;}
 public function setAttribute(int $attribute,mixed $value):bool{return true;}
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  if(!str_contains($query,'mirage_oauth_tokens'))throw new RuntimeException('unexpected SQL');
  return new NativeScopeTestStatement($this->row);
 }
}
$n=0;
function ok91(bool $x,string $label):void{global $n;if(!$x)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
$token=str_repeat('a',43);$now=1790130000;
$row=['token_hash'=>hash('sha256',$token),'client_id'=>'https://chatgpt.com/oauth/client.json',
 'connector_id'=>'mirage-engram','host_evidence_id'=>str_repeat('c',64),
 'resource'=>'https://kicom.rurtalbahn.info/api.php?q=ENGRAM_MCP',
 'scope'=>'engram.read','owner_binding'=>str_repeat('b',64),
 'credential_fingerprint'=>str_repeat('d',64),'issued_at'=>$now-20,'expires_at'=>$now+3600,'revoked'=>0];
$db=new NativeScopeTestDatabase($row);
ok91(KiComEngramOAuthTransactions::verify($db,$token,$now)!==null,'legacy read verified');
ok91(KiComEngramOAuthTransactions::verifyCombinedScope($db,$token,$now)===null,'read token cannot become combined');
$db->row['scope']='engram.read engram.write';
ok91(KiComEngramOAuthTransactions::verify($db,$token,$now)===null,'original read-only verifier unchanged');
$combined=KiComEngramOAuthTransactions::verifyCombinedScope($db,$token,$now);
ok91($combined!==null&&array_keys($combined)===['authenticated','connector_id','credential_fingerprint','owner_binding','host_evidence_id'],'separate combined verifier returns original host-gate identity');
$db->row['scope']='engram.write';
ok91(KiComEngramOAuthTransactions::verifyCombinedScope($db,$token,$now)===null,'write-only token not accepted as combined');
$db->row['scope']='engram.read engram.write';$db->row['revoked']=1;
ok91(KiComEngramOAuthTransactions::verifyCombinedScope($db,$token,$now)===null,'revoked token denied');
$db->row['revoked']=0;$db->row['expires_at']=$now;
ok91(KiComEngramOAuthTransactions::verifyCombinedScope($db,$token,$now)===null,'expired token denied');
$db->row['expires_at']=$now+3600;$db->row['resource']='https://other.invalid';
ok91(KiComEngramOAuthTransactions::verifyCombinedScope($db,$token,$now)===null,'foreign resource denied');
$db->row['resource']=$row['resource'];$db->row['owner_binding']='bad';
ok91(KiComEngramOAuthTransactions::verifyCombinedScope($db,$token,$now)===null,'invalid owner binding denied');
$db->row=false;
ok91(KiComEngramOAuthTransactions::verifyCombinedScope($db,$token,$now)===null,'missing row denied');
ok91(KiComEngramOAuthTransactions::verifyCombinedScope($db,'short',$now)===null,'malformed bearer denied');
echo "DEV91_SCOPE_ASSERTIONS=$n\n";
