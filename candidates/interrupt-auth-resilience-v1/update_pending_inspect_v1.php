<?php
declare(strict_types=1);

/* Read-only self-update pending inspector. No package mutation or authorization. */
function kicomUpdatePendingInspectV1(): array {
    $p=kicomSelfUpdatePending();
    if(!is_array($p))return ['ok'=>true,'code'=>'NO_PENDING','pending'=>false];
    $changed=[];foreach(($p['changed_paths']??[]) as $path)if(is_string($path)){$changed[]=substr($path,0,240);if(count($changed)>=128)break;}
    $reasons=[];foreach(($p['risk_reasons']??[]) as $reason)if(is_string($reason)){$reasons[]=substr($reason,0,300);if(count($reasons)>=64)break;}
    return ['ok'=>true,'code'=>'OK','pending'=>true,
        'from_version'=>(string)($p['from_version']??''),'to_version'=>(string)($p['to_version']??''),
        'zip_sha256'=>(string)($p['zip_sha256']??''),'manifest_sha256'=>(string)($p['manifest_sha256']??''),
        'genome_id'=>(string)($p['genome_id']??''),'genome_sha256'=>(string)($p['genome_sha256']??''),
        'kernel_update'=>!empty($p['kernel_update']),'zip_bytes'=>(int)($p['zip_bytes']??0),'files_count'=>(int)($p['files_count']??0),
        'install_files_count'=>(int)($p['install_files_count']??0),'preserved_state_files'=>(int)($p['preserved_state_files']??0),
        'source'=>(string)($p['source']??''),'risk_class'=>(string)($p['risk_class']??''),'risk_reasons'=>$reasons,'changed_paths'=>$changed,'created_at'=>(string)($p['created_at']??'')];
}
