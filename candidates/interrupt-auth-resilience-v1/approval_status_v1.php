<?php
declare(strict_types=1);

/* Read-only recovery for lost critical-execute responses.
 * Requires approval_id + exact binding_sha256. Grants no authority. */
function kicomAuthApprovalStatusReadV1(string $approvalId,string $bindingSha256): array {
    $approvalId=strtolower(trim($approvalId));$bindingSha256=strtolower(trim($bindingSha256));
    if(!preg_match('/^[a-f0-9]{24}$/',$approvalId)||!preg_match('/^[a-f0-9]{64}$/',$bindingSha256))return ['ok'=>false,'code'=>'APPROVAL_STATUS_INPUT_INVALID'];
    $row=kicomAuthApprovalGet($approvalId);if(!is_array($row))return ['ok'=>false,'code'=>'APPROVAL_NOT_FOUND'];
    if(!hash_equals((string)($row['binding_sha256']??''),$bindingSha256))return ['ok'=>false,'code'=>'APPROVAL_BINDING_MISMATCH'];
    $stored=(string)($row['status']??'unknown');$effective=$stored;
    if($stored==='pending'&&(int)($row['expires_at']??0)<time())$effective='expired';
    return ['ok'=>true,'code'=>'OK','approval_id'=>$approvalId,'action'=>(string)($row['action']??''),'risk'=>(string)($row['risk']??''),'status'=>$effective,'stored_status'=>$stored,'terminal'=>in_array($effective,['used','failed','expired'],true),'binding_sha256'=>$bindingSha256,'created_at'=>(string)($row['created_at']??''),'expires_at'=>(int)($row['expires_at']??0),'used_at'=>(string)($row['used_at']??''),'result_code'=>(string)($row['result_code']??'')];
}
