<?php
declare(strict_types=1);
require_once __DIR__.'/KiComMembraneShadow.php';
$n=0;
function check(bool $ok,string $label):void { global $n; ++$n; if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n"; }
$matrix=[
    ['kcl_https','in','GET'],['kcl_https','out','POST'],
    ['dev_api','in','POST'],['slack_events','in','POST'],
    ['slack_outbound','out','POST'],['mail_inbox','in','GET'],
    ['mail_outbound','out','POST'],['update_pull','in','GET'],
    ['update_push','in','POST'],['browser_admin','in','GET'],
    ['browser_opera','in','GET'],['github_mirror','out','POST']
];
foreach($matrix as [$ch,$direction,$method]) {
    $r=KiComMembraneShadow::observe($ch,$direction,$method);
    check($r['code']==='BOUNDARY_OBSERVED'
        && $r['channel']===$ch
        && $r['relevant_authority']==='original_runtime'
        && $r['message_accessed']===false
        && $r['communication_changed']===false
        && $r['action_authorized']===false, "Channel $ch/$direction/$method preserved without permission grant");
}
foreach([
    ['kcl_https','in','PATCH'],['update_push','in','GET'],
    ['arbitrary_visitor','in','GET'],['mail_outbound','third-party','POST']
] as [$ch,$direction,$method]) {
    $r=KiComMembraneShadow::tryObserve($ch,$direction,$method);
    check($r['code']==='UNKNOWN_BOUNDARY' && !$r['action_authorized']
        && !$r['communication_changed'], "Unknown boundary $ch/$direction/$method never changes transport");
}
$payloads=[
    "KCL/1\nOK hello\nFACT request_id=\"opaque\"\nEND\n",
    "KCL/1\r\nOK echo\r\nFACT value=\"\\n\\\\\\\"\"\r\nEND\r\n",
    "\0binary\r\n\r\n".str_repeat("\xff",256),
    '{"operation":"DEV_API","session_id":"secret-test","token":"sensitive","content":"\\u03b1"}',
    "POST /api.php?q=SLACK_EVENTS HTTP/1.1\r\nContent-Length: 0\r\n\r\n",
];
foreach($payloads as $i=>$payload) {
    $before=hash('sha256',$payload);
    $res=KiComMembraneShadow::tryObserve('kcl_https','in','POST');
    check(hash('sha256',$payload)===$before
        && $res['message_accessed']===false && !$res['action_authorized'],
        'Opaque transport fixture #'.$i.' remains byte-identical and unobserved');
    check(!str_contains(json_encode($res,JSON_THROW_ON_ERROR),'secret-test'),
        'No sensitive communication content in observation #'.$i);
}
$reflection=new ReflectionClass(KiComMembraneShadow::class);
$public=array_map(static fn(ReflectionMethod $x):string=>$x->name,
    $reflection->getMethods(ReflectionMethod::IS_PUBLIC));
sort($public);
check($public===['observe','tryObserve'],
    'Observer exposes no forwarding, execution, install, logging, file or credential API');
$method=$reflection->getMethod('observe');
check($method->getNumberOfParameters()===3,
    'Observer accepts channel, direction and method metadata only');
echo "MEMBRANE_SHADOW_TESTS_PASSED=$n\n";
