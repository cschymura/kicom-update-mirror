<?php
declare(strict_types=1);

/* Read-only release/canonical-memory consistency diagnostic.
 * It reports drift only; it never mutates memory, genome, runtime or authorization state. */
function kicomReleaseConsistencyExtractV1(string $raw,string $pattern): string {
    return preg_match($pattern,$raw,$m)?trim((string)($m[1]??'')):'';
}
function kicomReleaseConsistencyStatusV1(): array {
    $runtime=defined('KICOM_VERSION')?(string)constant('KICOM_VERSION'):'';
    $g=function_exists('kicomGenomeCurrent')?kicomGenomeCurrent():null;
    $genomeId=is_array($g)?(string)($g['id']??''):'';
    $generation=is_array($g)?(int)($g['generation']??0):0;
    $names=['PROJECT_STATE','CHANGELOG','NEXT','PROTOCOL'];$res=[];
    foreach($names as $name){$x=kicomReadMemoryResource($name);if(!is_array($x))return ['ok'=>false,'code'=>'CONSISTENCY_MEMORY_UNAVAILABLE','resource'=>$name];$res[$name]=$x;}
    $project=(string)($res['PROJECT_STATE']['raw']??$res['PROJECT_STATE']['content']??'');
    $changelog=(string)($res['CHANGELOG']['raw']??$res['CHANGELOG']['content']??'');
    $next=(string)($res['NEXT']['raw']??$res['NEXT']['content']??'');
    $protocol=(string)($res['PROTOCOL']['raw']??$res['PROTOCOL']['content']??'');
    $projectVersion=kicomReleaseConsistencyExtractV1($project,'/^VERSION\s+"([^"]+)"/m');
    $projectGenome=kicomReleaseConsistencyExtractV1($project,'/^FACT\s+genome_id="([^"]+)"/m');
    $projectGeneration=(int)kicomReleaseConsistencyExtractV1($project,'/^FACT\s+genome_generation=(\d+)/m');
    $projectOk=$runtime!==''&&hash_equals($runtime,$projectVersion)&&$genomeId!==''&&hash_equals($genomeId,$projectGenome)&&$generation>0&&$generation===$projectGeneration;
    $changeOk=$runtime!==''&&str_contains($changelog,'RELEASE "'.$runtime.'"');
    $nextOk=$runtime!==''&&preg_match('/^PRIORITY\s+1\s+goal="[^"]*'.preg_quote($runtime,'/').'[^"]*"/m',$next)===1;
    $batchLive=function_exists('kicomAuthPrepareWorkspaceProposalBatch');
    $protocolBatchCandidate=str_contains($protocol,'workspace_batch="candidate only')||str_contains($protocol,'workspace batch candidate');
    $protocolBatchLive=str_contains($protocol,'workspace_proposal_batch')||str_contains($protocol,'AUTH workspace_batch=');
    $protocolOk=!$batchLive||($protocolBatchLive&&!$protocolBatchCandidate);
    $stale=[];if(!$projectOk)$stale[]='PROJECT_STATE';if(!$changeOk)$stale[]='CHANGELOG';if(!$nextOk)$stale[]='NEXT';if(!$protocolOk)$stale[]='PROTOCOL';
    $hashes=[];foreach($names as $name)$hashes[$name]=(string)($res[$name]['sha256']??'');
    return ['ok'=>true,'code'=>empty($stale)?'CONSISTENT':'CANONICAL_MEMORY_STALE','consistent'=>empty($stale),'runtime_version'=>$runtime,'genome_id'=>$genomeId,'genome_generation'=>$generation,'project_state_version'=>$projectVersion,'project_state_genome_id'=>$projectGenome,'project_state_generation'=>$projectGeneration,'project_state_consistent'=>$projectOk,'changelog_has_runtime_release'=>$changeOk,'next_priority1_has_runtime'=>$nextOk,'protocol_workspace_batch_consistent'=>$protocolOk,'stale_resources'=>$stale,'memory_sha256'=>$hashes];
}
