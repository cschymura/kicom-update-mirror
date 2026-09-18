<?php
declare(strict_types=1);

$policyRaw=file_get_contents(__DIR__.'/readiness-managed-component-policy.json');
$policy=is_string($policyRaw)?json_decode($policyRaw,true):null;
if(!is_array($policy)||(int)($policy['schema']??0)!==1) throw new RuntimeException('POLICY_INVALID');
$d=(array)($policy['deployment']??[]);
if(($d['public_web_endpoint']??null)!==false) throw new RuntimeException('PUBLIC_ENDPOINT_MUST_BE_FALSE');
if(($d['promotion_authority']??null)!==false) throw new RuntimeException('PROMOTION_AUTHORITY_MUST_BE_FALSE');
if(($d['managed_by_lkg']??null)!==true) throw new RuntimeException('LKG_MANAGEMENT_REQUIRED');
foreach(['evaluator_destination','adapter_destination'] as $k){
    $p=(string)($d[$k]??'');
    if(!str_starts_with($p,'lib/')||str_contains($p,'..')) throw new RuntimeException('NON_PRIVATE_DESTINATION_'.$k);
}
$required=['diagnostic-only','no-promotion','no-authority-change','no-external-probe','no-healing','no-public-anonymous-endpoint','fail-closed-on-insufficient-or-failed-evidence'];
$inv=(array)($policy['invariants']??[]);
foreach($required as $r) if(!in_array($r,$inv,true)) throw new RuntimeException('MISSING_INVARIANT_'.$r);
foreach((array)($policy['source_files']??[]) as $src) if(!is_file(__DIR__.'/'.(string)$src)) throw new RuntimeException('SOURCE_MISSING_'.$src);
require_once __DIR__.'/cell-evolution-readiness-status.php';
$rf=new ReflectionFunction('kicomCellEvolutionReadinessStatus');
$body=file_get_contents((string)$rf->getFileName());
if(!is_string($body)||!str_contains($body,"'promotion_performed'=>false")||!str_contains($body,"'authority_changed'=>false")) throw new RuntimeException('ADAPTER_GUARDS_MISSING');
echo "READINESS_MANAGED_COMPONENT_POLICY_OK\n";
