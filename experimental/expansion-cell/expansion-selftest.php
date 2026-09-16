<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionProtocol.php';
require_once __DIR__.'/ExpansionRegistry.php';
require_once __DIR__.'/CellNode.php';
require_once __DIR__.'/ExpansionFtpDeployer.php';

function must(bool $ok,string $message): void {
    if (!$ok) { fwrite(STDERR,"FAIL: $message\n"); exit(1); }
}
function rmTree(string $dir): void {
    if (!is_dir($dir)) return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $p=$f->getPathname(); $f->isDir()?@rmdir($p):@unlink($p); }
    @rmdir($dir);
}

$base=sys_get_temp_dir().'/kicom-expansion-selftest-'.bin2hex(random_bytes(5));
@mkdir($base,0700,true);
try {
    $parentIdentity=KiComExpansionProtocol::createIdentity('cell-'.str_repeat('a',24));
    $parent=[
        'cell_id'=>$parentIdentity['cell_id'],
        'root_id'=>$parentIdentity['cell_id'],
        'generation'=>0,
        'public_key'=>$parentIdentity['public_key'],
        'base_url'=>'https://parent.example/kicom',
    ];
    $registry=new KiComExpansionRegistry($base.'/parent',$parent);

    $prep=$registry->prepare('https://child.example','/www/htdocs/example',3600);
    must(!empty($prep['ok']),'prepare');
    must(($prep['code']??'')==='EXPANSION_PREPARED','prepare code');
    must(str_ends_with((string)$prep['remote_cell_directory'],'/kicom'),'remote kicom directory');
    $token=(string)$prep['enrollment_token'];
    must(strlen($token)>=40,'strong enrollment token');

    $child=new KiComExpansionCellNode($base.'/child');
    $init=$child->initialize([
        'expansion_id'=>$prep['expansion_id'],
        'enrollment_token'=>$token,
        'base_url'=>$prep['child_base_url'],
        'parent_id'=>$parent['cell_id'],
        'parent_public_key'=>$parent['public_key'],
        'parent_base_url'=>$parent['base_url'],
        'capabilities'=>['federation.tick','status.report'],
    ]);
    must(!empty($init['ok']),'child initialize');

    $hello=$child->enrollmentHello();
    must(!empty($hello['ok']),'child hello');
    must(isset($hello['proof'],$hello['descriptor']),'hello proof+descriptor');

    $bad=$registry->enroll((string)$prep['expansion_id'],(array)$hello['descriptor'],str_repeat('0',64));
    must(empty($bad['ok'])&&($bad['code']??'')==='EXPANSION_ENROLLMENT_PROOF_INVALID','bad proof rejected');

    $accepted=$registry->enroll((string)$prep['expansion_id'],(array)$hello['descriptor'],(string)$hello['proof']);
    must(!empty($accepted['ok']),'parent accepts enrollment');
    must(($accepted['cell']['parent_id']??'')===$parent['cell_id'],'parent lineage');
    must(($accepted['cell']['root_id']??'')===$parent['cell_id'],'root lineage');
    must(($accepted['cell']['generation']??0)===1,'generation increment');

    $replay=$registry->enroll((string)$prep['expansion_id'],(array)$hello['descriptor'],(string)$hello['proof']);
    must(empty($replay['ok'])&&($replay['code']??'')==='EXPANSION_NOT_ENROLLABLE','enrollment one-time');

    $activationPayload=[
        'root_id'=>$accepted['cell']['root_id'],
        'parent_id'=>$accepted['cell']['parent_id'],
        'generation'=>$accepted['cell']['generation'],
    ];
    $activation=KiComExpansionProtocol::signEnvelope(
        $parent['cell_id'],
        (string)$accepted['cell']['cell_id'],
        'EXPANSION_ACTIVATE',
        $activationPayload,
        $parentIdentity['secret_key']
    );
    $active=$child->activate($activation);
    must(!empty($active['ok'])&&($active['code']??'')==='CELL_ACTIVE','child activation');
    must(!is_file($base.'/child/bootstrap.private.json'),'bootstrap token erased after activation');

    $tick=KiComExpansionProtocol::signEnvelope(
        $parent['cell_id'],
        (string)$accepted['cell']['cell_id'],
        'FEDERATION_TICK',
        ['tick_id'=>'tick-1','budget_ms'=>1000],
        $parentIdentity['secret_key']
    );
    $reply=$child->handleParentMessage($tick);
    must(isset($reply['signature']),'signed child tick reply');
    $replyCheck=KiComExpansionProtocol::verifyEnvelope(
        $reply,
        (string)$accepted['cell']['cell_id'],
        $parent['cell_id'],
        (string)$accepted['cell']['public_key']
    );
    must(!empty($replyCheck['ok']),'parent verifies child reply');

    $stale=KiComExpansionProtocol::signEnvelope(
        $parent['cell_id'],
        (string)$accepted['cell']['cell_id'],
        'FEDERATION_TICK',
        ['tick_id'=>'old'],
        $parentIdentity['secret_key'],
        time()-1000
    );
    $staleReply=$child->handleParentMessage($stale);
    must(empty($staleReply['ok'])&&($staleReply['code']??'')==='FEDERATION_MESSAGE_STALE','stale message rejected');

    $cells=$registry->cells();
    must(count($cells)===1&&($cells[0]['state']??'')==='active','active cell registry');

    $persisted=(string)file_get_contents($base.'/parent/expansions/'.$prep['expansion_id'].'.json');
    must(!str_contains($persisted,$token),'plaintext enrollment token never persisted');
    must(!str_contains($persisted,'enrollment_verifier'),'verifier erased after enrollment');

    echo "KiCom Expansion/Federation v1 selftest: PASS\n";
} finally {
    rmTree($base);
}
