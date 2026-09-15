<?php
declare(strict_types=1);

/* Standalone regression harness for proposal_approval_v1.php.
 * It stubs only the KiCom functions used by the candidate and writes to a temp tree.
 */
$root=sys_get_temp_dir().'/kicom-batch-test-'.bin2hex(random_bytes(4));
@mkdir($root.'/pending',0700,true);@mkdir($root.'/stage',0700,true);@mkdir($root.'/auth',0700,true);
function kicomAuthDir():string{global $root;return $root.'/auth';}
function kicomPendingDir():string{global $root;return $root.'/pending';}
function kicomStageDir():string{global $root;return $root.'/stage';}
function kicomSafeId(string $id):?string{$id=strtolower(trim($id));return preg_match('/^[a-f0-9]{8,80}$/',$id)?$id:null;}
function kicomSafeRelativePath(string $p):?string{$p=trim(str_replace('\\','/',$p));if($p===''||str_contains($p,'..')||!preg_match('~^[A-Za-z0-9_./-]+$~',$p))return null;return $p;}
function kicomCurrentHash(string $p):string{$f=kicomStageDir().'/'.$p;return is_file($f)?(hash_file('sha256',$f)?:''):'NEW';}
function kicomValidateContent(string $p,string $c):array{return ['status'=>'ok','message'=>'OK'];}
$GLOBALS['history']=[];$GLOBALS['fail_path']='';
function kicomRecordRevision(string $p,string $raw,string $action):void{$GLOBALS['history'][]=[$p,hash('sha256',$raw),$action];}
function kicomAtomicStageWrite(string $p,string $c,string $action,?string $base=null):array{
    if($GLOBALS['fail_path']===$p)return ['ok'=>false,'code'=>'SIM_FAIL'];
    if($base!==null&&kicomCurrentHash($p)!==$base)return ['ok'=>false,'code'=>'BASE_CONFLICT'];
    $f=kicomStageDir().'/'.$p;@mkdir(dirname($f),0700,true);file_put_contents($f,$c);kicomRecordRevision($p,$c,$action);
    return ['ok'=>true,'sha256'=>hash('sha256',$c),'revision'=>'r'.count($GLOBALS['history'])];
}
$GLOBALS['events']=[];function kicomLivingEvent(string $t,string $s='info',array $d=[]):void{$GLOBALS['events'][]=[$t,$s,$d];}
$GLOBALS['approval']=null;function kicomAuthApprovalCreate(string $sid,string $action,array $binding,array $payload,string $risk):array{
    $GLOBALS['approval']=compact('sid','action','binding','payload','risk');
    return ['ok'=>true,'approval_id'=>'abcdefabcdefabcdefabcdef','binding_sha256'=>hash('sha256',json_encode([$action,$binding])),'risk'=>$risk,'expires_in'=>3600,'binding'=>$binding];
}
require __DIR__.'/proposal_approval_v1.php';
function proposal(string $id,string $path,string $content):string{
    $sha=hash('sha256',$content);$p=['kind'=>'workspace_write','path'=>$path,'base_sha256'=>'NEW','sha256'=>$sha,'content_b64'=>base64_encode($content)];
    file_put_contents(kicomPendingDir().'/'.$id.'.json',json_encode($p));return $sha;
}
function check(bool $cond,string $msg):void{if(!$cond){fwrite(STDERR,"FAIL $msg\n");exit(1);}echo "OK $msg\n";}

$a='aaaaaaaa';$b='bbbbbbbb';$shaA=proposal($a,'cell/a.php','A');$shaB=proposal($b,'cell/b.php','B');
$r=kicomAuthPrepareWorkspaceProposalBatch('s1',"$a:$shaA,$b:$shaB");check(!empty($r['ok']),'prepare');$ap=$GLOBALS['approval'];
$x=kicomApplyWorkspaceProposalBatchApproval($ap['payload'],$ap['binding']);check(!empty($x['ok']),'apply');
check(is_file(kicomStageDir().'/cell/a.php')&&is_file(kicomStageDir().'/cell/b.php'),'files created');
check(!is_file(kicomPendingDir().'/'.$a.'.json')&&!is_file(kicomPendingDir().'/'.$b.'.json'),'pending consumed');

$c='cccccccc';$shaC=proposal($c,'cell/c.php','C');$r=kicomAuthPrepareWorkspaceProposalBatch('s1',"$c:$shaC");$ap=$GLOBALS['approval'];
$row=json_decode((string)file_get_contents(kicomPendingDir().'/'.$c.'.json'),true);$row['content_b64']=base64_encode('CHANGED');file_put_contents(kicomPendingDir().'/'.$c.'.json',json_encode($row));
$x=kicomApplyWorkspaceProposalBatchApproval($ap['payload'],$ap['binding']);check(empty($x['ok'])&&($x['code']??'')==='PROPOSAL_BINDING_CHANGED','tamper rejected');
check(!is_file(kicomStageDir().'/cell/c.php'),'tamper no write');

$d='dddddddd';$e='eeeeeeee';$shaD=proposal($d,'cell/d.php','D');$shaE=proposal($e,'cell/e.php','E');
$r=kicomAuthPrepareWorkspaceProposalBatch('s1',"$d:$shaD,$e:$shaE");$ap=$GLOBALS['approval'];$GLOBALS['fail_path']='cell/e.php';
$x=kicomApplyWorkspaceProposalBatchApproval($ap['payload'],$ap['binding']);check(empty($x['ok'])&&($x['code']??'')==='PROPOSAL_BATCH_WRITE_FAILED','write failure surfaced');
check(!is_file(kicomStageDir().'/cell/d.php'),'compensation removed first write');
check(count($GLOBALS['history'])>=4,'compensation history preserved');

echo "ALL TESTS PASSED\n";
