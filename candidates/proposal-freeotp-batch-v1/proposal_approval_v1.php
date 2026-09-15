<?php
declare(strict_types=1);

/* Candidate module for KiCom >=0.9.13.
 * Scope: transaction-bound FreeOTP approval of NEW workspace proposals only.
 * This module is intentionally non-executable on its own and must be integrated
 * through the existing Human Authorization Bridge / verifier path.
 */

function kicomWorkspaceProposalBatchLockFile(): string {
    return kicomAuthDir().'/workspace_proposal_batch.lock';
}

function kicomWorkspaceProposalBatchParseSpec(string $spec): array {
    $spec=trim($spec);
    if($spec===''||strlen($spec)>4096)return ['ok'=>false,'code'=>'PROPOSAL_BATCH_SPEC_INVALID'];
    $parts=array_values(array_filter(array_map('trim',explode(',',$spec)),fn($x)=>$x!==''));
    if(count($parts)<1||count($parts)>16)return ['ok'=>false,'code'=>'PROPOSAL_BATCH_COUNT_INVALID'];
    $out=[];$seen=[];
    foreach($parts as $part){
        if(!preg_match('/^([a-f0-9]{8,80}):([a-f0-9]{64})$/i',$part,$m))return ['ok'=>false,'code'=>'PROPOSAL_BATCH_ITEM_INVALID'];
        $id=strtolower($m[1]);$sha=strtolower($m[2]);
        if(isset($seen[$id]))return ['ok'=>false,'code'=>'PROPOSAL_BATCH_DUPLICATE_ID','proposal_id'=>$id];
        $seen[$id]=true;$out[]=['proposal_id'=>$id,'content_sha256'=>$sha];
    }
    return ['ok'=>true,'items'=>$out];
}

function kicomWorkspaceProposalBatchSnapshot(string $id,string $expectedContentSha): array {
    $id=kicomSafeId($id);
    if($id===null)return ['ok'=>false,'code'=>'PROPOSAL_ID_INVALID'];
    $file=kicomPendingDir().'/'.$id.'.json';
    if(!is_file($file))return ['ok'=>false,'code'=>'PROPOSAL_NOT_FOUND','proposal_id'=>$id];
    $raw=@file_get_contents($file);
    if($raw===false)return ['ok'=>false,'code'=>'PROPOSAL_READ_FAILED','proposal_id'=>$id];
    $p=json_decode($raw,true);
    if(!is_array($p))return ['ok'=>false,'code'=>'PROPOSAL_INVALID','proposal_id'=>$id];
    $kind=(string)($p['kind']??'');
    if($kind!=='workspace_write')return ['ok'=>false,'code'=>'PROPOSAL_KIND_FORBIDDEN','proposal_id'=>$id,'kind'=>$kind];
    $path=kicomSafeRelativePath((string)($p['path']??''));
    if($path===null)return ['ok'=>false,'code'=>'PROPOSAL_PATH_INVALID','proposal_id'=>$id];
    $base=(string)($p['base_sha256']??'');
    if(strtoupper($base)!=='NEW')return ['ok'=>false,'code'=>'PROPOSAL_BATCH_NEW_ONLY','proposal_id'=>$id,'path'=>$path];
    $content=base64_decode((string)($p['content_b64']??''),true);
    if($content===false)return ['ok'=>false,'code'=>'PROPOSAL_CONTENT_INVALID','proposal_id'=>$id];
    $contentSha=hash('sha256',$content);
    $declared=strtolower((string)($p['sha256']??''));
    $expectedContentSha=strtolower(trim($expectedContentSha));
    if(!preg_match('/^[a-f0-9]{64}$/',$expectedContentSha)||!hash_equals($contentSha,$expectedContentSha)||!hash_equals($contentSha,$declared))
        return ['ok'=>false,'code'=>'PROPOSAL_CONTENT_SHA_MISMATCH','proposal_id'=>$id];
    $v=kicomValidateContent($path,$content);
    if(($v['status']??'')==='error')return ['ok'=>false,'code'=>'PROPOSAL_VALIDATION_FAILED','proposal_id'=>$id,'validation'=>(string)($v['message']??'')];
    if(kicomCurrentHash($path)!=='NEW')return ['ok'=>false,'code'=>'PROPOSAL_BASE_CONFLICT','proposal_id'=>$id,'path'=>$path,'current_sha256'=>kicomCurrentHash($path)];
    return [
        'ok'=>true,
        'proposal_id'=>$id,
        'proposal_file_sha256'=>hash('sha256',$raw),
        'content_sha256'=>$contentSha,
        'path'=>$path,
        'base_sha256'=>'NEW',
        'bytes'=>strlen($content),
        'content_b64'=>base64_encode($content),
    ];
}

function kicomWorkspaceProposalBatchBinding(array $items): array {
    $canonical=[];
    foreach($items as $x)$canonical[]=[
        'proposal_id'=>(string)$x['proposal_id'],
        'proposal_file_sha256'=>(string)$x['proposal_file_sha256'],
        'content_sha256'=>(string)$x['content_sha256'],
        'path'=>(string)$x['path'],
        'base_sha256'=>'NEW',
    ];
    $json=json_encode($canonical,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)throw new RuntimeException('PROPOSAL_BATCH_CANONICALIZE_FAILED');
    return [
        'action'=>'workspace_proposal_batch',
        'proposal_count'=>count($canonical),
        'batch_sha256'=>hash('sha256',$json),
        'scope'=>'workspace-new-only',
    ];
}

function kicomAuthPrepareWorkspaceProposalBatch(string $sessionId,string $spec): array {
    $parsed=kicomWorkspaceProposalBatchParseSpec($spec);
    if(empty($parsed['ok']))return $parsed;
    $items=[];
    foreach($parsed['items'] as $request){
        $s=kicomWorkspaceProposalBatchSnapshot((string)$request['proposal_id'],(string)$request['content_sha256']);
        if(empty($s['ok']))return $s;
        $items[]=$s;
    }
    usort($items,fn($a,$b)=>strcmp((string)$a['proposal_id'],(string)$b['proposal_id']));
    $binding=kicomWorkspaceProposalBatchBinding($items);
    $payload=['items'=>$items];
    $r=kicomAuthApprovalCreate($sessionId,'workspace_proposal_batch',$binding,$payload,'red');
    if(!empty($r['ok']))$r['items']=$items;
    return $r;
}

function kicomWorkspaceProposalBatchRecheck(array $bound): array {
    $id=(string)($bound['proposal_id']??'');
    $file=kicomPendingDir().'/'.$id.'.json';
    if(!is_file($file))return ['ok'=>false,'code'=>'PROPOSAL_NOT_FOUND','proposal_id'=>$id];
    $raw=@file_get_contents($file);
    if($raw===false)return ['ok'=>false,'code'=>'PROPOSAL_READ_FAILED','proposal_id'=>$id];
    if(!hash_equals((string)($bound['proposal_file_sha256']??''),hash('sha256',$raw)))
        return ['ok'=>false,'code'=>'PROPOSAL_BINDING_CHANGED','proposal_id'=>$id];
    $p=json_decode($raw,true);
    if(!is_array($p)||(string)($p['kind']??'')!=='workspace_write')return ['ok'=>false,'code'=>'PROPOSAL_KIND_FORBIDDEN','proposal_id'=>$id];
    $path=kicomSafeRelativePath((string)($p['path']??''));
    if($path===null||!hash_equals((string)($bound['path']??''),$path))return ['ok'=>false,'code'=>'PROPOSAL_PATH_CHANGED','proposal_id'=>$id];
    if(strtoupper((string)($p['base_sha256']??''))!=='NEW'||kicomCurrentHash($path)!=='NEW')return ['ok'=>false,'code'=>'PROPOSAL_BASE_CONFLICT','proposal_id'=>$id,'path'=>$path];
    $content=base64_decode((string)($p['content_b64']??''),true);
    if($content===false)return ['ok'=>false,'code'=>'PROPOSAL_CONTENT_INVALID','proposal_id'=>$id];
    $sha=hash('sha256',$content);
    if(!hash_equals((string)($bound['content_sha256']??''),$sha)||!hash_equals(strtolower((string)($p['sha256']??'')),$sha))return ['ok'=>false,'code'=>'PROPOSAL_CONTENT_SHA_MISMATCH','proposal_id'=>$id];
    $v=kicomValidateContent($path,$content);
    if(($v['status']??'')==='error')return ['ok'=>false,'code'=>'PROPOSAL_VALIDATION_FAILED','proposal_id'=>$id,'validation'=>(string)($v['message']??'')];
    return ['ok'=>true,'proposal_id'=>$id,'path'=>$path,'content'=>$content,'content_sha256'=>$sha,'pending_file'=>$file];
}

function kicomWorkspaceProposalBatchCompensate(array $applied): array {
    $rolled=[];$errors=[];
    foreach(array_reverse($applied) as $x){
        $path=(string)($x['path']??'');$sha=(string)($x['sha256']??'');
        $safe=kicomSafeRelativePath($path);if($safe===null){$errors[]=['path'=>$path,'code'=>'INVALID_PATH'];continue;}
        $full=kicomStageDir().'/'.$safe;
        if(!is_file($full)){continue;}
        $raw=@file_get_contents($full);
        if($raw===false||!hash_equals($sha,hash('sha256',$raw))){$errors[]=['path'=>$safe,'code'=>'ROLLBACK_CONFLICT'];continue;}
        kicomRecordRevision($safe,$raw,'proposal_batch_compensating_rollback');
        if(!@unlink($full)){$errors[]=['path'=>$safe,'code'=>'ROLLBACK_UNLINK_FAILED'];continue;}
        $rolled[]=$safe;
    }
    return ['ok'=>empty($errors),'rolled_back'=>$rolled,'errors'=>$errors];
}

function kicomApplyWorkspaceProposalBatchApproval(array $payload,array $binding): array {
    $items=is_array($payload['items']??null)?$payload['items']:[];
    if(count($items)<1||count($items)>16)return ['ok'=>false,'code'=>'PROPOSAL_BATCH_PAYLOAD_INVALID'];
    try{$expected=kicomWorkspaceProposalBatchBinding($items);}catch(Throwable $e){return ['ok'=>false,'code'=>'PROPOSAL_BATCH_CANONICALIZE_FAILED'];}
    foreach(['action','proposal_count','batch_sha256','scope'] as $k){
        if((string)($binding[$k]??'')!==(string)($expected[$k]??''))return ['ok'=>false,'code'=>'PROPOSAL_BATCH_BINDING_MISMATCH'];
    }
    $lock=@fopen(kicomWorkspaceProposalBatchLockFile(),'c+');
    if($lock===false||!@flock($lock,LOCK_EX)){if(is_resource($lock))@fclose($lock);return ['ok'=>false,'code'=>'PROPOSAL_BATCH_LOCK_FAILED'];}
    try{
        $ready=[];
        foreach($items as $bound){$r=kicomWorkspaceProposalBatchRecheck($bound);if(empty($r['ok']))return $r;$ready[]=$r;}
        $paths=array_map(fn($x)=>(string)$x['path'],$ready);
        if(count($paths)!==count(array_unique($paths)))return ['ok'=>false,'code'=>'PROPOSAL_BATCH_DUPLICATE_PATH'];
        $applied=[];$results=[];
        foreach($ready as $r){
            $w=kicomAtomicStageWrite((string)$r['path'],(string)$r['content'],'proposal_batch_freeotp','NEW');
            if(empty($w['ok'])){
                $comp=kicomWorkspaceProposalBatchCompensate($applied);
                return ['ok'=>false,'code'=>'PROPOSAL_BATCH_WRITE_FAILED','failed_proposal_id'=>(string)$r['proposal_id'],'write_code'=>(string)($w['code']??'UNKNOWN'),'compensation'=>$comp,'results'=>$results];
            }
            $sha=(string)($w['sha256']??$r['content_sha256']);
            $applied[]=['path'=>(string)$r['path'],'sha256'=>$sha];
            $results[]=['proposal_id'=>(string)$r['proposal_id'],'path'=>(string)$r['path'],'sha256'=>$sha,'revision'=>(string)($w['revision']??'')];
        }
        foreach($ready as $r)@unlink((string)$r['pending_file']);
        kicomLivingEvent('workspace_proposal_batch_approved','info',['count'=>count($results),'batch_sha256'=>(string)($binding['batch_sha256']??''),'paths'=>array_values($paths)]);
        return ['ok'=>true,'code'=>'PROPOSAL_BATCH_APPLIED','applied'=>count($results),'batch_sha256'=>(string)($binding['batch_sha256']??''),'results'=>$results];
    } finally {
        @flock($lock,LOCK_UN);@fclose($lock);
    }
}
