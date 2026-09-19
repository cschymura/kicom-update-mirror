<?php
declare(strict_types=1);
require_once __DIR__.'/KiComChildBirthReadiness.php';
$n=0;
function check(bool $ok,string $description):void {
    global $n; ++$n;
    if(!$ok)throw new RuntimeException('FAIL '.$description);
    echo 'PASS '.$description."\n";
}
$candidate=[
    'ok'=>true,'native_genome_bound'=>false,'recovery_bound'=>false,
    'deployment_authorized'=>false,
    'child_public_fingerprint'=>hash('sha256','child-pub-ci')
];
$inherited="KCL/1\nOK living_status\nFACT genome_id=\"kicom-0.9.26-g25r3\"\nEND\n";
$report=KiComChildBirthReadiness::inspect($inherited,$candidate);
check($report['code']==='NATIVE_GENOME_STILL_RELEASE_PARENT_UNBOUND'
    && $report['observed_native_genome_id']==='kicom-0.9.26-g25r3',
    'Historical release genome is recognized as unbound child identity');
check(!$report['daughter_born'] && !$report['native_genome_bound']
    && !$report['recovery_qualified'] && !$report['egress_qualified']
    && !$report['communication_parity_qualified']
    && !$report['deployment_authorized']
    && !$report['external_action_authorized'],
    'Unbound release cannot grant birth, communication, egress or deployment authority');
check(KiComChildBirthReadiness::inspect('', $candidate)['code']==='NATIVE_RUNTIME_UNVERIFIED',
    'Missing runtime KCL cannot establish child identity');
check(KiComChildBirthReadiness::inspect("KCL/2\nFACT genome_id=\"child\"\n", $candidate)['code']
    ==='NATIVE_RUNTIME_UNVERIFIED','Unknown KCL protocol rejected');
check(KiComChildBirthReadiness::inspect("KCL/1\nFACT genome_id=\"\"\n",$candidate)['code']
    ==='NATIVE_RUNTIME_UNVERIFIED','Empty genome ID rejected');
$forged=$candidate;$forged['native_genome_bound']=true;
check(KiComChildBirthReadiness::inspect($inherited,$forged)['code']==='HOST_CANDIDATE_NOT_VERIFIED',
    'Caller cannot grant native genome identity through candidate flag');
$forged=$candidate;$forged['deployment_authorized']=true;
check(KiComChildBirthReadiness::inspect($inherited,$forged)['code']==='HOST_CANDIDATE_NOT_VERIFIED',
    'Caller cannot substitute birth candidate for deployment approval');
$forged=$candidate;$forged['child_public_fingerprint']='bad-key';
check(KiComChildBirthReadiness::inspect($inherited,$forged)['code']==='HOST_CANDIDATE_NOT_VERIFIED',
    'Malformed child public identity rejected');
$changed="KCL/1\nOK living_status\nFACT genome_id=\"kicom-new-identity\"\nEND\n";
$report=KiComChildBirthReadiness::inspect($changed,$candidate);
check($report['code']==='NATIVE_GENOME_DISTINCTNESS_INSUFFICIENT'
    && !$report['daughter_born'] && !$report['deployment_authorized'],
    'Merely changing public genome ID does not independently attest a daughter');
check(KiComChildBirthReadiness::inspect($inherited,[])['code']==='HOST_CANDIDATE_NOT_VERIFIED',
    'Absent protected lineage candidate fails closed');
$methods=array_map(static fn(ReflectionMethod $m)=>$m->name,
    (new ReflectionClass(KiComChildBirthReadiness::class))->getMethods(ReflectionMethod::IS_PUBLIC));
check($methods===['inspect'],'Read-only birth gate has no code-modification or promotion API');
echo "KICOM_CHILD_BIRTH_GATE_TESTS_PASSED=$n\n";
