<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionProtocol.php';
require_once __DIR__.'/ExpansionCronRelay.php';

function cronMust(bool $ok,string $message): void {
    if (!$ok) { fwrite(STDERR,"FAIL: $message\n"); exit(1); }
}

$parentKeys=KiComExpansionProtocol::createIdentity('cell-'.str_repeat('a',24));
$childKeys=KiComExpansionProtocol::createIdentity('cell-'.str_repeat('b',24));
$parent=[
    'cell_id'=>$parentKeys['cell_id'],
    'public_key'=>$parentKeys['public_key'],
    'base_url'=>'https://parent.example/kicom',
    'state'=>'active',
];
$child=[
    'cell_id'=>$childKeys['cell_id'],
    'public_key'=>$childKeys['public_key'],
    'base_url'=>'https://child.example/kicom',
    'state'=>'active',
];

$tick=KiComExpansionCronRelay::createTick($parent,$parentKeys['secret_key'],$child,['budget_ms'=>1500]);
$check=KiComExpansionProtocol::verifyEnvelope($tick,$parent['cell_id'],$child['cell_id'],$parent['public_key']);
cronMust(!empty($check['ok']),'child verifies parent tick');
cronMust(($tick['operation']??'')==='FEDERATION_TICK','tick operation');

$result=KiComExpansionProtocol::signEnvelope(
    $child['cell_id'],
    $parent['cell_id'],
    'FEDERATION_TICK_RESULT',
    ['status'=>'ok','received_message_id'=>$tick['message_id']],
    $childKeys['secret_key']
);
$verified=KiComExpansionCronRelay::verifyTickResult($result,$parent,$child);
cronMust(!empty($verified['ok']),'parent verifies child tick result');

// Regression guard for the live 2026-09-17 failure: transport metadata must
// never be injected into a successful signed federation envelope before verify.
$mutated=$result;
$mutated['http_status']=200;
$mutatedCheck=KiComExpansionCronRelay::verifyTickResult($mutated,$parent,$child);
cronMust(empty($mutatedCheck['ok'])&&($mutatedCheck['code']??'')==='FEDERATION_SIGNATURE_INVALID','post-signature field injection is rejected');
$transportSource=(string)file_get_contents(__DIR__.'/ExpansionHttpTransport.php');
cronMust(!str_contains($transportSource,"return \$decoded+['http_status'=>\$status]"),'https transport preserves successful protocol payloads');
cronMust(str_contains($transportSource,'return $decoded;'),'https transport returns decoded success unchanged');

$wrong=KiComExpansionProtocol::signEnvelope(
    $child['cell_id'],
    $parent['cell_id'],
    'FEDERATION_STATUS_RESULT',
    ['status'=>'ok'],
    $childKeys['secret_key']
);
$wrongCheck=KiComExpansionCronRelay::verifyTickResult($wrong,$parent,$child);
cronMust(empty($wrongCheck['ok'])&&($wrongCheck['code']??'')==='FEDERATION_TICK_RESULT_OPERATION_INVALID','wrong result operation rejected');

echo "KiCom Expansion Cron Relay selftest: PASS\n";
