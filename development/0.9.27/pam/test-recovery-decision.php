<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPamRecoveryDecision.php';
$n=0;
function decisionOk(bool $condition,string $label):void {
    global $n;
    ++$n;
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
$sha=str_repeat('a',64);
$id='20260919135700-1234567890';
$candidate=['ok'=>true,'code'=>'SEQUENCED_CANDIDATE_REQUIRES_ANCHOR',
    'snapshot_id'=>$id,'snapshot_sha256'=>$sha,
    'restore_permitted'=>false,'automatic_recovery_permitted'=>false];
$triad=['ok'=>true,'files'=>[
    'db'=>['present'=>true,'bytes'=>3,'sha256'=>hash('sha256','db1')],
    'wal'=>['present'=>true,'bytes'=>4,'sha256'=>hash('sha256','wal1')],
    'shm'=>['present'=>true,'bytes'=>4,'sha256'=>hash('sha256','shm1')]
]];
$claim=['ok'=>true,'code'=>'LOCAL_SEQUENCE_MATCHES_SUPPLIED_INDEPENDENT_CLAIM',
    'snapshot_id'=>$id,'snapshot_sha256'=>$sha,
    'independent_anchor_authenticated_here'=>false,'restore_permitted'=>false];
$preserved=['quiesced'=>true,'originals_preserved'=>true,'originals_independently_verified'=>true];
$review=static fn(array $c,array $b,array $a,array $h,array $p):array =>
    KiComPamRecoveryDecision::review($c,$b,$a,$h,$p);
$check=static function(array $result,string $code,string $label):void {
    decisionOk(($result['code']??null)===$code
        && ($result['restore_permitted']??null)===false
        && ($result['automatic_recovery_permitted']??null)===false
        && ($result['inspection_only']??null)===true,$label);
};
$check($review([], $triad,$triad,$claim,$preserved),
    'BLOCKED_CANDIDATE_UNVERIFIED','Missing candidate aborts before originals');
$bad=$candidate;$bad['restore_permitted']=true;
$check($review($bad,$triad,$triad,$claim,$preserved),
    'BLOCKED_CANDIDATE_UNVERIFIED','Caller cannot smuggle permission through candidate');
$bad=$candidate;$bad['snapshot_sha256']='invalid';
$check($review($bad,$triad,$triad,$claim,$preserved),
    'BLOCKED_CANDIDATE_UNVERIFIED','Malformed candidate digest rejected');
$check($review($candidate,[],$triad,$claim,$preserved),
    'BLOCKED_ORIGINAL_INVENTORY_MISSING','Missing original fingerprint refuses review');
$bad=$triad;$bad['files']['db']['sha256']='invalid';
$check($review($candidate,$bad,$bad,$claim,$preserved),
    'BLOCKED_ORIGINAL_INVENTORY_INVALID','Malformed DB digest fails closed');
$changed=$triad;$changed['files']['db']['sha256']=hash('sha256','db2');
$check($review($candidate,$triad,$changed,$claim,$preserved),
    'BLOCKED_ORIGINAL_CHANGED_DB','Changed original database detected');
$changed=$triad;$changed['files']['wal']['sha256']=hash('sha256','wal2');
$check($review($candidate,$triad,$changed,$claim,$preserved),
    'BLOCKED_ORIGINAL_CHANGED_WAL','Changed original WAL detected');
$changed=$triad;$changed['files']['shm']['sha256']=hash('sha256','shm2');
$check($review($candidate,$triad,$changed,$claim,$preserved),
    'BLOCKED_ORIGINAL_CHANGED_SHM','Changed original SHM detected');
$changed=$triad;$changed['files']['wal']=['present'=>false];
$check($review($candidate,$triad,$changed,$claim,$preserved),
    'BLOCKED_ORIGINAL_CHANGED_WAL','Missing WAL after observation rejected');
$legacy=$candidate;$legacy['code']='LEGACY_CANDIDATE_REQUIRES_REVIEW';
$check($review($legacy,$triad,$triad,$claim,$preserved),
    'BLOCKED_LEGACY_REQUIRES_RECONCILIATION','Legacy candidate cannot silently enter sequenced recovery');
$check($review($candidate,$triad,$triad,[],$preserved),
    'BLOCKED_HIGH_WATER_MISSING_OR_DIVERGENT','Missing high-water evidence rejected');
$bad=$claim;$bad['snapshot_sha256']=str_repeat('b',64);
$check($review($candidate,$triad,$triad,$bad,$preserved),
    'BLOCKED_HIGH_WATER_MISSING_OR_DIVERGENT','Divergent claim rejected');
$check($review($candidate,$triad,$triad,$claim,$preserved),
    'BLOCKED_ANCHOR_NOT_INDEPENDENTLY_AUTHENTICATED',
    'Matching local claim is not independent anchor authentication');
$claimed=$claim;$claimed['independent_anchor_authenticated_here']=true;
$check($review($candidate,$triad,$triad,$claimed,[]),
    'BLOCKED_ORIGINAL_PRESERVATION_UNVERIFIED',
    'Even claimed anchor evidence cannot replace preserved originals');
$full=$review($candidate,$triad,$triad,$claimed,$preserved);
$check($full,'REVIEW_REQUIRED_AT_PROTECTED_RECOVERY_BOUNDARY',
    'All supplied claims still require protected executor review without restore rights');
decisionOk(($full['evidence']['candidate_sha256']??null)===$sha
    && ($full['stage']??null)==='REVIEW',
    'Review outcome binds exact candidate checksum without an execution method');
echo "PAM_RECOVERY_DECISION_TESTS_PASSED=$n\n";
