<?php
declare(strict_types=1);
require_once __DIR__.'/KiComMembraneTransparentTap.php';
$n=0;
function tapOk(bool $ok,string $name):void {
    global $n;++$n;
    if(!$ok)throw new RuntimeException('FAIL '.$name);
    echo "PASS $name\n";
}
foreach (['kcl_https','dev_api','slack_events','mail_outbound','update_push','browser_opera'] as $channel) {
    $calls=0;$observed=[];
    $result=KiComMembraneTransparentTap::run($channel,'out','POST',
        static function () use (&$calls,$channel):array {
            ++$calls;
            return ['opaque'=>$channel, 'token'=>'unchanged', 'body'=>"\x00\xff"];
        },
        static function(string $ch,string $direction,string $method) use (&$observed):void {
            $observed=[$ch,$direction,$method];
        });
    tapOk($calls===1 && $result['opaque']===$channel
        && $result['token']==='unchanged' && $result['body']==="\x00\xff",
        $channel.' operation invoked exactly once with untouched bytes');
    tapOk($observed===[$channel,'out','POST'],
        $channel.' telemetry receives only three metadata fields');
}
$calls=0;$errorCalls=0;
$original=function()use(&$calls):string {++$calls;return 'unchanged response';};
$broken=function()use(&$errorCalls):void {++$errorCalls;throw new RuntimeException('observer failed');};
tapOk(KiComMembraneTransparentTap::run('kcl_https','out','POST',$original,$broken)
    ==='unchanged response' && $calls===1 && $errorCalls===1,
    'Failed telemetry does not block, duplicate, or modify an existing operation');
tapOk(KiComMembraneTransparentTap::run('kcl_https','out','POST',$original,null)
    ==='unchanged response' && $calls===2,
    'Unavailable optional telemetry does not change the existing operation');
$failures=0;$message='PROTECTED_AUTH_REQUIRED';
try {
    KiComMembraneTransparentTap::run('update_push','out','POST',
        static function()use(&$failures,$message):never {
            ++$failures;
            throw new DomainException($message,403);
        },$broken);
    throw new RuntimeException('FAIL original authorization error was swallowed');
} catch(DomainException $e) {
    tapOk($failures===1 && $e->getMessage()===$message && $e->getCode()===403,
        'Original protected operation denial propagates unchanged without replay');
}
$observed=null;$failure=null;
try {
    KiComMembraneTransparentTap::run('mail_outbound','out','POST',
        static function()use(&$failure):never {
            $failure=new LogicException('original SMTP failure',451);
            throw $failure;
        },null);
    throw new RuntimeException('FAIL original transport error was swallowed');
} catch(LogicException $e) {
    tapOk($e===$failure,
        'Original transport failure object propagates unchanged, without retry');
}
echo "MEMBRANE_TRANSPARENT_TAP_TESTS_PASSED=$n\n";
