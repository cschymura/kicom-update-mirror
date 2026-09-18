<?php
declare(strict_types=1);

/* KiCom 0.9.26 trusted DEV endpoint adapter.
   Authority remains DEV-scoped. Production/self-update/recovery/secrets are absent. */

function kicomDevStore(): string { return kicomVarDir().'/dev_zone'; }
function kicomDevPasskeys(): KiComPasskeyBridge { return new KiComPasskeyBridge(kicomDevStore().'/passkeys'); }
function kicomDevSessions(): KiComDevSessionManager { return new KiComDevSessionManager(kicomDevStore().'/sessions'); }
function kicomDevRouter(): KiComDevRouter {
    $handlers=array_merge(KiComDevRuntimeBindings::handlers(),KiComDevDiagnostics::handlers());
    return new KiComDevRouter(kicomDevSessions(),$handlers);
}
function kicomDevReady(): array {
    $p=kicomDevPasskeys()->ready();$s=kicomDevSessions()->ready();$b=KiComDevRuntimeBindings::ready();
    return ['ok'=>!empty($p['ok'])&&!empty($s['ok'])&&!empty($b['ok']),
        'code'=>(!empty($p['ok'])&&!empty($s['ok'])&&!empty($b['ok']))?'DEV_READY':'DEV_NOT_READY',
        'passkey_ready'=>!empty($p['ok']),'session_ready'=>!empty($s['ok']),'bindings_ready'=>!empty($b['ok']),
        'credential_count'=>method_exists(kicomDevPasskeys(),'credentialCount')?kicomDevPasskeys()->credentialCount():0,
        'session_ttl'=>$s['ttl']??KiComDevSessionManager::DEFAULT_TTL,
        'idle_ttl'=>$s['idle_ttl']??KiComDevSessionManager::DEFAULT_IDLE_TTL,
        'scope'=>'dev','token_rotation'=>false];
}
function kicomDevAuthDispatch(array $data): array {
    $op=strtoupper(trim((string)($data['operation']??'')));$payload=is_array($data['payload']??null)?$data['payload']:[];
    $passkeys=kicomDevPasskeys();$sessions=kicomDevSessions();
    $adapter=new KiComDevAuthAdapter($passkeys,$sessions,kicomDevStore().'/locks');
    $flow=new KiComDevAuthFlow($passkeys,$adapter);
    if($op==='DEV_READY')return kicomDevReady();
    if($op==='DEV_ENROLL_BEGIN'){
        /* Legacy/fallback enrollment transport only. Normal DEV entry is passkey-only. */
        if(!function_exists('kicomTotpVerifyConsume'))return ['ok'=>false,'code'=>'TOTP_VERIFIER_UNAVAILABLE'];
        $code=preg_replace('/\\D+/','',(string)($payload['code']??''));$v=kicomTotpVerifyConsume($code,'dev_passkey_enrollment');
        if(empty($v['ok']))return ['ok'=>false,'code'=>(string)($v['code']??'TOTP_CODE_REJECTED')];
        return $passkeys->createEnrollmentTicket((string)($payload['account']??'Christoph'));
    }
    if($op==='DEV_ENROLL_OPTIONS')return $passkeys->registrationOptions((string)($payload['enrollment_id']??''));
    if($op==='DEV_ENROLL_COMPLETE')return $passkeys->completeRegistration((string)($payload['enrollment_id']??''),is_array($payload['credential']??null)?$payload['credential']:[],(string)($payload['label']??'KiCom DEV Passkey'));
    if($op==='DEV_AUTH_BEGIN')return $flow->begin();
    if($op==='DEV_AUTH_OPTIONS')return $flow->options((string)($payload['challenge_id']??''));
    if($op==='DEV_AUTH_COMPLETE')return $flow->complete((string)($payload['challenge_id']??''),is_array($payload['credential']??null)?$payload['credential']:[],(string)($payload['label']??'KiCom DEV'));
    return ['ok'=>false,'code'=>'DEV_AUTH_OPERATION_UNKNOWN'];
}
function kicomDevApiDispatch(array $server,string $raw): array {
    return (new KiComDevHttpAdapter(kicomDevRouter()))->handle($server,$raw);
}
function kicomDevBridgeDispatch(array $q): array {
    $sid=strtolower(trim((string)($q['sid']??'')));$key=strtolower(trim((string)($q['key']??'')));
    $op=strtoupper(trim((string)($q['op']??'')));$encoded=(string)($q['p']??'');
    if($op==='')return ['ok'=>false,'code'=>'DEV_BRIDGE_OPERATION_REQUIRED'];
    if($encoded==='')$payload=[];
    else{
        if(!preg_match('/^[A-Za-z0-9_-]{1,32768}$/',$encoded))return ['ok'=>false,'code'=>'DEV_BRIDGE_PAYLOAD_INVALID'];
        $s=strtr($encoded,'-_','+/');$pad=strlen($s)%4;if($pad)$s.=str_repeat('=',4-$pad);
        $raw=base64_decode($s,true);if($raw===false||strlen($raw)>24576)return ['ok'=>false,'code'=>'DEV_BRIDGE_PAYLOAD_INVALID'];
        $payload=json_decode($raw,true);if(!is_array($payload))return ['ok'=>false,'code'=>'DEV_BRIDGE_PAYLOAD_JSON_INVALID'];
    }
    $r=kicomDevRouter()->handle($op,$sid,$key,$payload);
    $r['transport']='dev-get-bridge';$r['credential_scope']='dev-only';return $r;
}
