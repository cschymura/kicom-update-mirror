<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramOAuthOptionalParams.php';
$n=0;
function ok(bool $condition,string $label):void {
    global $n; if(!$condition)throw new RuntimeException('FAIL '.$label);
    $n++; echo "PASS $label\n";
}
function rejected(callable $operation,string $label):void {
    try{$operation();}catch(RuntimeException){ok(true,$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
$keys=['client_id','redirect_uri','response_type','scope','resource','state','code_challenge','code_challenge_method'];
$base=array_fill_keys($keys,'synthetic');
ok(KiComEngramOAuthOptionalParams::authorizationQuery($base)===$base,'ordinary query unchanged');
foreach(['de-DE','en-US','de-DE en-US','zh-Hant-TW'] as $locale){
    $result=KiComEngramOAuthOptionalParams::authorizationQuery($base+['ui_locales'=>$locale]);
    ok($result===$base,'display hint removed: '.$locale);
}
foreach(['','../','de_DE','de-DE%0A',str_repeat('a',129),['de-DE'],null,77] as $locale){
    rejected(fn()=>KiComEngramOAuthOptionalParams::authorizationQuery($base+['ui_locales'=>$locale]),'invalid display hint denied');
}
$unexpected=$base+['ui_locales'=>'de-DE','unrecognized_security_field'=>'value'];
$result=KiComEngramOAuthOptionalParams::authorizationQuery($unexpected);
ok(isset($result['unrecognized_security_field']),'unexpected security field is never silently stripped');
$changed=$base; $changed['scope']='engram.write';
$result=KiComEngramOAuthOptionalParams::authorizationQuery($changed+['ui_locales'=>'de-DE']);
ok($result['scope']==='engram.write','scope is never rewritten or implicitly approved');
$missing=$base; unset($missing['client_id']);
$result=KiComEngramOAuthOptionalParams::authorizationQuery($missing+['ui_locales'=>'de-DE']);
ok(!isset($result['client_id']),'missing security field is never supplied');
$parent=getenv('KICOM_PARENT_PACKAGE_DIR');
if(is_string($parent)&&$parent!==''){
    $source=rtrim($parent,'/').'/modules/engram/KiComEngramOAuthTransactions.php';
    if(!is_file($source))throw new RuntimeException('Missing exact parent source '.$source);
    require $source;
    $method=new ReflectionMethod(KiComEngramOAuthTransactions::class,'keys');
    rejected(fn()=>$method->invoke(null,$base+['ui_locales'=>'de-DE'],$keys),'actual original strict validator rejects previously failing request');
    $method->invoke(null,KiComEngramOAuthOptionalParams::authorizationQuery($base+['ui_locales'=>'de-DE']),$keys);
    ok(true,'actual original validator accepts normalized request');
    rejected(fn()=>$method->invoke(null,$result,$keys),'actual original validator still rejects missing client_id');
    rejected(fn()=>$method->invoke(null,KiComEngramOAuthOptionalParams::authorizationQuery($unexpected),$keys),'actual original validator still rejects unknown key');
}
echo "KICOM_DEV78_ASSERTIONS=$n\n";
