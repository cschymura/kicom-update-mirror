<?php
declare(strict_types=1);
const KICOM_VERSION='0.9.15';
$GLOBALS['mem']=[
 'PROJECT_STATE'=>"PROJECT kicom\nVERSION \"0.9.14\"\nFACT genome_id=\"kicom-0.9.14-g15\"\nFACT genome_generation=15\nEND_PROJECT kicom\n",
 'CHANGELOG'=>"CHANGELOG kicom\nRELEASE \"0.9.14\" date=\"2026-09-16\" change=\"old\"\n",
 'NEXT'=>"NEXT kicom\nPRIORITY 1 goal=\"Operate on KiCom 0.9.14 as trusted baseline\"\n",
 'PROTOCOL'=>"PROTOCOL KCL/1\nNOTE workspace_batch=\"candidate only; live 0.9.14 AUTH_APPROVAL_PREPARE supports self_update_install only\"\n",
];
function kicomReadMemoryResource(string $name):?array{$raw=$GLOBALS['mem'][$name]??null;return is_string($raw)?['raw'=>$raw,'sha256'=>hash('sha256',$raw)]:null;}
function kicomGenomeCurrent():?array{return ['id'=>'kicom-0.9.15-g16','version'=>'0.9.15','generation'=>16];}
function kicomAuthPrepareWorkspaceProposalBatch(string $sid,string $csv):array{return ['ok'=>false];}
require __DIR__.'/release_consistency_status_v1.php';
function check(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "OK $m\n";}
$r=kicomReleaseConsistencyStatusV1();
check(!empty($r['ok'])&&empty($r['consistent']),'stale state detected');
check(($r['code']??'')==='CANONICAL_MEMORY_STALE','stale code');
$stale=$r['stale_resources']??[];sort($stale);check($stale===['CHANGELOG','NEXT','PROJECT_STATE','PROTOCOL'],'all four stale resources identified');
check(($r['project_state_version']??'')==='0.9.14'&&($r['genome_generation']??0)===16,'runtime and stale memory reported separately');
$GLOBALS['mem']['PROJECT_STATE']="PROJECT kicom\nVERSION \"0.9.15\"\nFACT genome_id=\"kicom-0.9.15-g16\"\nFACT genome_generation=16\nEND_PROJECT kicom\n";
$GLOBALS['mem']['CHANGELOG'].="RELEASE \"0.9.15\" date=\"2026-09-16\" change=\"workspace batch live\"\n";
$GLOBALS['mem']['NEXT']="NEXT kicom\nPRIORITY 1 goal=\"Operate on KiCom 0.9.15 as trusted baseline\"\n";
$GLOBALS['mem']['PROTOCOL']="PROTOCOL KCL/1\nAUTH workspace_batch=\"AUTH_APPROVAL_PREPARE action=workspace_proposal_batch proposal_ids=...\"\nRULE \"workspace_proposal_batch execution requires fresh FreeOTP\"\n";
$r=kicomReleaseConsistencyStatusV1();
check(!empty($r['ok'])&&!empty($r['consistent'])&&($r['code']??'')==='CONSISTENT','synchronized state consistent');
check(($r['stale_resources']??[])===[],'no stale resources after sync');
check(!empty($r['project_state_consistent'])&&!empty($r['changelog_has_runtime_release'])&&!empty($r['next_priority1_has_runtime'])&&!empty($r['protocol_workspace_batch_consistent']),'all consistency dimensions green');
echo "ALL RELEASE CONSISTENCY TESTS PASSED\n";
