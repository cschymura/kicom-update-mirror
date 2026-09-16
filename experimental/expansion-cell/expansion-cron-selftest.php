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
