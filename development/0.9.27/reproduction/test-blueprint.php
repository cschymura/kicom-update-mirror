<?php
declare(strict_types=1);
require_once __DIR__.'/KiComReproductionBlueprint.php';
$n=0;
function blueprintOk(bool $ok,string $label):void {
    global $n; ++$n;
    if (!$ok)throw new RuntimeException('FAIL '.$label);
    echo "PASS $label\n";
}
$package='6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f';
$parent=hash('sha256','public-mother-instance-ci-only');
$make=static fn($g,$sha,$finger,$label)=>KiComReproductionBlueprint::compile($g,$sha,$finger,$label);
$b=$make('kicom-0.9.26-g25r3',$package,$parent,'daughter-one');
blueprintOk($b['ok'] && $b['code']==='DAUGHTER_BLUEPRINT_CANDIDATE',
    'Verified public release reference creates inert daughter blueprint');
blueprintOk(!$b['clone_created'] && !$b['runtime_identity_issued']
    && !$b['deploy_permitted'] && !$b['external_action_authorized'],
    'Blueprint never claims daughter activation or reproduction authority');
$manifest=json_decode($b['manifest_json'],true,32,JSON_THROW_ON_ERROR);
blueprintOk($manifest['verified_software_package']['sha256']===$package
    && $manifest['parent_public_identity_sha256']===$parent
    && $manifest['daughter_label']==='daughter-one',
    'Public software lineage and explicit daughter designation are bound');
blueprintOk($manifest['inheritance']['runtime_memory']==='new_empty_instance'
    && $manifest['inheritance']['private_credentials']==='never'
    && $manifest['inheritance']['parent_sessions']==='never'
    && $manifest['inheritance']['parent_authorizations']==='never'
    && $manifest['inheritance']['production_access']==='never',
    'Memory, secrets, sessions and permission grants do not pass to daughter');
blueprintOk(count($manifest['transport_contract'])===8
    && in_array('slack',$manifest['transport_contract'],true)
    && in_array('mail',$manifest['transport_contract'],true)
    && in_array('opera_browser',$manifest['transport_contract'],true)
    && in_array('chat_update',$manifest['transport_contract'],true),
    'Existing communication channels remain explicit nonregression requirements');
blueprintOk(in_array('per_instance_key_generation',$manifest['host_provisioning_required'],true)
    && in_array('operator_control_and_shutdown',$manifest['host_provisioning_required'],true)
    && in_array('full_communication_parity',$manifest['host_provisioning_required'],true),
    'Independent daughter identity and operator/communication gates mandatory');
blueprintOk(hash('sha256',$b['manifest_json'])===$b['manifest_sha256'],
    'Manifest content is reproducibly hashed');
$repeat=$make('kicom-0.9.26-g25r3',$package,$parent,'daughter-one');
blueprintOk($b===$repeat,'Same public parent and daughter designation yield canonical identical blueprint');
$c=$make('kicom-0.9.26-g25r3',$package,$parent,'daughter-two');
blueprintOk($c['ok'] && $c['manifest_sha256']!==$b['manifest_sha256'],
    'Distinct daughter labels create different candidate manifests, not cloned identity');
$inspect=KiComReproductionBlueprint::inspect($b['manifest_json'],$b['manifest_sha256']);
blueprintOk($inspect['ok'] && !$inspect['deploy_permitted']
    && !$inspect['runtime_identity_issued'] && !$inspect['independent_package_verified_here'],
    'Canonical inspection cannot issue independent package trust or deployment grant');
blueprintOk(!$make('kicom-0.9.26-g25r2',$package,$parent,'daughter-one')['ok'],
    'Stale reported mother genome refused');
blueprintOk(!$make('kicom-0.9.26-g25r3',str_repeat('b',64),$parent,'daughter-one')['ok'],
    'Unverified binary source digest refused');
blueprintOk(!$make('kicom-0.9.26-g25r3',$package,'private-key-material','daughter-one')['ok'],
    'Private material cannot masquerade as public parent fingerprint');
blueprintOk(!$make('kicom-0.9.26-g25r3',$package,$parent,'../../escape')['ok'],
    'Path traversal cannot become daughter identifier');
blueprintOk(!$make('kicom-0.9.26-g25r3',$package,$parent,'daughter one')['ok'],
    'Uncanonical label rejected');
blueprintOk(!KiComReproductionBlueprint::inspect($b['manifest_json'],str_repeat('0',64))['ok'],
    'Untrusted manifest hash mismatch rejected');
$altered=$manifest;$altered['inheritance']['private_credentials']='copy-parent';
$tamper=json_encode($altered,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
blueprintOk(!KiComReproductionBlueprint::inspect($tamper,hash('sha256',$tamper))['ok'],
    'Even a newly rehashed manifest cannot silently copy mother secrets');
$altered=$manifest;$altered['transport_contract']=['slack'];
$tamper=json_encode($altered,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
blueprintOk(!KiComReproductionBlueprint::inspect($tamper,hash('sha256',$tamper))['ok'],
    'No child may silently lose original communication capabilities');
$altered=$manifest;$altered['unverified_private_key']='leak';
$tamper=json_encode($altered,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
blueprintOk(!KiComReproductionBlueprint::inspect($tamper,hash('sha256',$tamper))['ok'],
    'Unknown additional private fields and extra properties rejected');
$altered=$manifest;$altered['activation']='autonomous-production';
$tamper=json_encode($altered,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
blueprintOk(!KiComReproductionBlueprint::inspect($tamper,hash('sha256',$tamper))['ok'],
    'Blueprint cannot grant autonomous external deployment');
$changed=$manifest;$changed['parent_public_identity_sha256']=hash('sha256','another-mother');
$tamper=json_encode($changed,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
blueprintOk(!KiComReproductionBlueprint::inspect($tamper,$b['manifest_sha256'])['ok'],
    'Lineage change is detected by original manifest digest');
$methods=array_map(static fn(ReflectionMethod $m):string=>$m->name,
    (new ReflectionClass(KiComReproductionBlueprint::class))->getMethods(ReflectionMethod::IS_PUBLIC));
sort($methods);
blueprintOk($methods===['compile','inspect'],
    'Blueprint exposes no spawning, key issuance, network, credential or recovery API');
echo "KICOM_REPRODUCTION_BLUEPRINT_TESTS_PASSED=$n\n";
