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
function seedChildRuntime(string $root): void {
    @mkdir($root.'/lib',0700,true);@mkdir($root.'/var',0700,true);
    foreach(['ExpansionProtocol.php','CellNode.php','CellLiving.php','CellPerceptionAction.php'] as $f) must(copy(__DIR__.'/'.$f,$root.'/lib/'.$f),'seed lib '.$f);
    foreach(['common.php','bootstrap.php','federation.php','status.php','doctor.php'] as $f) must(copy(__DIR__.'/cell-runtime/'.$f,$root.'/'.$f),'seed endpoint '.$f);
    must(copy(__DIR__.'/cell-runtime/living-schema.json',$root.'/living-schema.json'),'seed living schema');
}
function jsonFile(string $path): array { $j=json_decode((string)file_get_contents($path),true); must(is_array($j),'json '.$path); return $j; }

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

    $childRoot=$base.'/child-root';seedChildRuntime($childRoot);
    $child=new KiComExpansionCellNode($childRoot.'/var');
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
    must(!empty($init['living_ready']),'complete living substrate at birth');
    must(!empty($init['perception_action_ready']),'perception/action ready at birth');
    $born=$child->status();
    must(is_array($born)&&!empty($born['living_ready']),'status proves living ready before enrollment');
    must(!empty($born['perception_action_ready']),'status proves perception/action ready before enrollment');
    foreach(['identity','canonical_memory','workspace','observer','genome_lkg','immune','evolution','perception','action'] as $sub) must(!empty($born['living']['subsystems'][$sub]),'born subsystem '.$sub);
    foreach(['PROJECT_STATE.kcl','ARCHITECTURE.kcl','PROTOCOL.kcl','DECISIONS.kcl','CHANGELOG.kcl','NEXT.kcl'] as $f) must(is_file($childRoot.'/var/living/memory/'.$f),'local canonical memory '.$f);
    foreach(['current.json','history.jsonl','changes.jsonl','neighbors.json'] as $f) must(is_file($childRoot.'/var/living/perception/'.$f),'perception memory '.$f);
    foreach(['model.json','history.jsonl','boundaries.json','expansion-opportunities.json'] as $f) must(is_file($childRoot.'/var/living/action/'.$f),'action memory '.$f);

    $perception=jsonFile($childRoot.'/var/living/perception/current.json');
    must(($perception['unknowns'][0]['state']??'')==='UNKNOWN','unprobed external remains UNKNOWN');
    must(($perception['environment']['arbitrary_filesystem']??'')==='FORBIDDEN','arbitrary filesystem explicitly FORBIDDEN');
    $actionModel=jsonFile($childRoot.'/var/living/action/model.json');
    $actionIndex=[];foreach($actionModel['actions']??[] as $a)if(is_array($a))$actionIndex[(string)($a['id']??'')]=$a;
    must(($actionIndex['protected.external.write']['state']??'')==='FORBIDDEN','protected external write forbidden without boundary authorization');
    must(($actionIndex['internal.capability.extend']['promotion']??'')==='DEFERRED','internal executable promotion deferred');

    $living=new KiComExpansionCellLiving($childRoot.'/var');
    $doctor=$living->doctor(false);must(!empty($doctor['ok']),'doctor healthy after birth');
    $originalStatus=(string)file_get_contents($childRoot.'/status.php');
    file_put_contents($childRoot.'/status.php',$originalStatus."\n// deliberate drift\n");
    $drift=$living->doctor(false);must(empty($drift['ok'])&&($drift['status']['drift_count']??0)===1,'doctor detects managed runtime drift');
    $healed=$living->doctor(true);must(!empty($healed['ok']),'immune heals from local LKG');
    must(hash('sha256',(string)file_get_contents($childRoot.'/status.php'))===hash('sha256',$originalStatus),'LKG restores exact runtime');

    $candidateContent="<?php\ndeclare(strict_types=1);\n// inert candidate fitness fixture\n";
    $candidate=$living->stageCandidate(['changes'=>[['path'=>'status.php','sha256'=>hash('sha256',$candidateContent),'content_b64'=>base64_encode($candidateContent)]]]);
    must(!empty($candidate['ok'])&&($candidate['fitness']['fitness']??'')==='pass','static evolution fitness passes valid inert candidate');
    must(($candidate['fitness']['eligible_for_internal_promotion']??true)===false,'candidate promotion not active');
    must(($candidate['promotion_state']??'')==='DEFERRED','candidate promotion explicitly deferred');
    must(is_file($childRoot.'/var/living/evolution/candidates/'.$candidate['candidate_id'].'.json'),'candidate persisted only in inert candidate area');

    $hello=$child->enrollmentHello();
    must(!empty($hello['ok']),'child hello');
    must(isset($hello['proof'],$hello['descriptor']),'hello proof+descriptor');
    must(($hello['descriptor']['living_ready']??false)===true,'enrollment proves cell was complete before trust');
    must(($hello['descriptor']['perception_action_ready']??false)===true,'enrollment proves perception/action existed before trust');

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
    must(!is_file($childRoot.'/var/bootstrap.private.json'),'bootstrap token erased after activation');
    $activeStatus=$child->status();
    must(!empty($activeStatus['living_ready'])&&!empty($activeStatus['perception_action_ready']),'activation preserves intrinsic living+PA readiness');

    $neighbors=jsonFile($childRoot.'/var/living/perception/neighbors.json');
    $parentSeen=false;foreach($neighbors['neighbors']??[] as $n)if(is_array($n)&&($n['cell_id']??'')===$parent['cell_id']){$parentSeen=(($n['state']??'')==='AVAILABLE');}
    must($parentSeen,'signed activation marks parent AVAILABLE');

    $historyBefore=count(file($childRoot.'/var/living/perception/history.jsonl',FILE_IGNORE_NEW_LINES)?:[]);
    $actionsBefore=count(file($childRoot.'/var/living/action/history.jsonl',FILE_IGNORE_NEW_LINES)?:[]);
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
    must(($reply['payload']['living_ready']??false)===true,'tick reports intrinsic living readiness');
    must(($reply['payload']['perception_action_ready']??false)===true,'tick reports perception/action readiness');
    must(($reply['payload']['known_neighbors']??0)>=1,'tick reports known neighbor');
    must(($reply['payload']['known_boundaries']??0)>=1,'tick reports known boundaries');
    $historyAfter=count(file($childRoot.'/var/living/perception/history.jsonl',FILE_IGNORE_NEW_LINES)?:[]);
    $actionsAfter=count(file($childRoot.'/var/living/action/history.jsonl',FILE_IGNORE_NEW_LINES)?:[]);
    must($historyAfter>$historyBefore,'tick appends perception history');
    must($actionsAfter>$actionsBefore,'tick appends action history');

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
