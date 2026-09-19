<?php
declare(strict_types=1);
require_once __DIR__.'/KiComMembraneSlackBridgeEvidence.php';
$n=0;
function bridgeCheck(bool $ok,string $label):void {
    global $n; ++$n;
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
$e=[
    'workspace_id'=>'T_TEST_WORKSPACE',
    'conversation_id'=>'C_TEST_GROUP_DM',
    'probe_id'=>'probe-0192837465',
    'expected_kicom_sender_id'=>'U_KICOM_TEST_BOT',
    'chatgpt_read_ok'=>true,
    'chatgpt_send_ok'=>true,
    'send_probe_id'=>'probe-0192837465',
    'kicom_live_slack_configured'=>false,
    'kicom_runtime_workspace_id'=>'T_TEST_WORKSPACE',
    'kicom_runtime_conversation_id'=>'C_TEST_CHANNEL_NOT_DM',
    'kicom_ack_probe_id'=>null,
    'kicom_ack_conversation_id'=>null,
    'kicom_ack_workspace_id'=>null,
    'kicom_ack_sender_id'=>null,
    'kicom_ack_provider_verified'=>false,
    'kicom_ack_runtime_verified'=>false,
];
$check=static function(array $e,string $code,bool $chatgpt,bool $kicom,string $label):void {
    $r=KiComMembraneSlackBridgeEvidence::inspect($e);
    bridgeCheck($r['code']===$code
        && $r['chatgpt_transport_confirmed']===$chatgpt
        && $r['kicom_runtime_confirmed']===$kicom
        && $r['direct_two_way_confirmed']===($chatgpt&&$kicom)
        && !$r['action_authorized']
        && !$r['runtime_configuration_changed']
        && $r['message_trust']==='UNTRUSTED_EXTERNAL_INPUT',$label);
};
$check($e,'CHATGPT_CONNECTED_KICOM_RUNTIME_UNVERIFIED',true,false,
    'User Group DM plus ChatGPT read/send does not demonstrate KiCom runtime connection');
$bad=$e;unset($bad['conversation_id']);
$check($bad,'MISSING_BOUND_CHANNEL_IDENTITY',false,false,'Missing conversation identity rejected');
$bad=$e;$bad['chatgpt_read_ok']=false;
$check($bad,'CHATGPT_TRANSPORT_UNVERIFIED',false,false,'Send without verified read is not confirmed bidirectional ChatGPT');
$bad=$e;$bad['send_probe_id']='different-probe';
$check($bad,'CHATGPT_TRANSPORT_UNVERIFIED',false,false,'Unrelated probe send cannot satisfy handshake');
$bad=$e;$bad['kicom_live_slack_configured']=true;
$check($bad,'CHATGPT_CONNECTED_KICOM_RUNTIME_UNVERIFIED',true,false,'Configured bot with different allowlisted conversation is not connected Group DM');
$base=$e;
$base['kicom_live_slack_configured']=true;
$base['kicom_runtime_conversation_id']=$e['conversation_id'];
$check($base,'KICOM_RUNTIME_ACK_UNVERIFIED',true,false,'Live channel binding alone is not a verified KiCom ACK');
$ack=$base;
$ack['kicom_ack_probe_id']=$e['probe_id'];
$ack['kicom_ack_conversation_id']=$e['conversation_id'];
$ack['kicom_ack_workspace_id']=$e['workspace_id'];
$ack['kicom_ack_sender_id']=$e['expected_kicom_sender_id'];
$ack['kicom_ack_provider_verified']=true;
$ack['kicom_ack_runtime_verified']=true;
$check($ack,'TWO_WAY_TRANSPORT_OBSERVED_NO_AUTHORITY',true,true,
    'Fully source-verified synthetic handshake establishes transport only');
$bad=$ack;$bad['kicom_ack_sender_id']='U_IMPERSONATOR';
$check($bad,'KICOM_RUNTIME_ACK_UNVERIFIED',true,false,'Wrong sender is not KiCom');
$bad=$ack;$bad['kicom_ack_provider_verified']=false;
$check($bad,'KICOM_RUNTIME_ACK_UNVERIFIED',true,false,'Unverified message author rejected');
$bad=$ack;$bad['kicom_ack_runtime_verified']=false;
$check($bad,'KICOM_RUNTIME_ACK_UNVERIFIED',true,false,'Provider ACK alone does not prove KiCom runtime read');
$bad=$ack;$bad['kicom_ack_probe_id']='replayed-old';
$check($bad,'KICOM_RUNTIME_ACK_UNVERIFIED',true,false,'Unrelated or replayed ACK cannot complete handshake');
$bad=$ack;$bad['kicom_ack_conversation_id']='C_OTHER_GROUP';
$check($bad,'KICOM_RUNTIME_ACK_UNVERIFIED',true,false,'Wrong conversation rejected');
$bad=$ack;$bad['kicom_ack_workspace_id']='T_OTHER';
$check($bad,'KICOM_RUNTIME_ACK_UNVERIFIED',true,false,'Wrong workspace rejected');
$bad=$ack;$bad['kicom_runtime_workspace_id']='T_OTHER';
$check($bad,'CHATGPT_CONNECTED_KICOM_RUNTIME_UNVERIFIED',true,false,'KiCom runtime must bind to same workspace');
echo "MEMBRANE_SLACK_BRIDGE_TESTS_PASSED=$n\n";
