<?php
declare(strict_types=1);
require __DIR__ . '/KiComEngramActivationTransaction.php';
$checks=0;
function ok2(bool $v,string $m):void{global $checks;if(!$v)throw new RuntimeException('FAIL '.$m);$checks++;echo "PASS $m\n";}
function deny2(callable $f,string $m):void{try{$f();}catch(Throwable $e){ok2(true,$m);return;}throw new RuntimeException('FAIL '.$m);}
function fixture():array{$now=new DateTimeImmutable('2026-09-21T02:00:00Z');$h=hash('sha256','host-40');$o=hash('sha256','owner-40');$host=['eligible'=>true,'status'=>'HOST_ISOLATION_EVIDENCE_COMPLETE_API_STILL_INACTIVE','evidence_id'=>$h,'checked_at_utc'=>'2026-09-21T01:55:00Z'];$owner=['schema'=>'mirage-owner-readiness/v1','verified'=>true,'owner_binding'=>$o,'host_evidence_id'=>$h,'checked_at_utc'=>'2026-09-21T01:56:00Z','private_api_inactive'=>true,'mcp_connector_connected'=>false];$a=['schema'=>'mirage-activation-approval/v1','approved'=>true,'purpose'=>'activate-private-engram','owner_binding'=>$o,'host_evidence_id'=>$h,'approval_nonce'=>hash('sha256','approval-40'),'approved_at_utc'=>'2026-09-21T01:59:00Z'];return [$now,$h,$o,$host,$owner,$a];}
function db():PDO{return new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
[$now,$h,$o,$host,$owner,$a]=fixture();$d=db();
ok2(KiComEngramActivationTransaction::state($d)==='inactive','starts inactive');
$r=KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$a,$now);
ok2($r['activated']===true&&$r['status']==='SYNTHETIC_ACTIVATION_TRANSACTION_COMMITTED','valid scoped approval commits');
ok2(KiComEngramActivationTransaction::state($d)==='active','committed state active');
deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$a,$now),'replay or already active denied');
[$now,$h,$o,$host,$owner,$a]=fixture();$d=db();$bad=$a;$bad['approved']=false;deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$bad,$now),'unapproved request denied');ok2(KiComEngramActivationTransaction::state($d)==='inactive','unapproved leaves inactive');
$bad=$a;$bad['purpose']='diagnostic';deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$bad,$now),'wrong purpose denied');
$bad=$a;$bad['owner_binding']=hash('sha256','foreign');deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$bad,$now),'foreign owner approval denied');
$bad=$a;$bad['host_evidence_id']=hash('sha256','foreign-host');deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$bad,$now),'foreign host approval denied');
$bad=$a;$bad['approved_at_utc']='2026-09-21T01:40:00Z';deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$bad,$now),'stale approval denied');
$bad=$a;$bad['approved_at_utc']='2026-09-21T02:01:00Z';deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$bad,$now),'future approval denied');
$bad=$a;$bad['secret']='forbidden';deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$bad,$now),'unknown secret field denied');
$d=db();deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$a,$now,function(){throw new RuntimeException('synthetic failure after pending');}),'mid-transaction failure propagates');ok2(KiComEngramActivationTransaction::state($d)==='inactive','mid-transaction failure rolls back to inactive');
$r=KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$a,$now);ok2($r['activated']===true,'rolled-back nonce can be retried because no commit occurred');
[$now,$h,$o,$host,$owner,$a]=fixture();$d=db();$a2=$a;$a2['approval_nonce']=hash('sha256','different-nonce');KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$a,$now);deny2(fn()=>KiComEngramActivationTransaction::apply($d,$host,$owner,$o,$a2,$now),'second approval cannot reactivate active state');
echo "KICOM_ENGRAM_ACTIVATION_TRANSACTION_TESTS_PASSED=$checks\n";
