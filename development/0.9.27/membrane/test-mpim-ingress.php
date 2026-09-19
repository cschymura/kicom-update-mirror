<?php
declare(strict_types=1);
require_once __DIR__.'/KiComMembraneSlackMpimIngress.php';
$checks=0;
function mpimOk(bool $cond,string $label):void {
    global $checks; ++$checks;
    if (!$cond) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
$secret='test-only-staging-secret-no-production-use';
$now=1789826700;
$team='T_SANDBOX_TEAM';
$channel='G_SANDBOX_GROUP';
$payload=[
    'type'=>'event_callback','team_id'=>$team,'event_id'=>'Ev123456789_TEST',
    'event'=>['type'=>'message','channel_type'=>'mpim',
        'channel'=>$channel,'user'=>'U_TEST_SENDER','text'=>'synthetic hello',
        'ts'=>'1789826700.000123']
];
$raw=json_encode($payload,JSON_THROW_ON_ERROR);
$headers=['x-slack-request-timestamp'=>(string)$now];
$headers['x-slack-signature']='v0='.hash_hmac('sha256',
    'v0:'.$now.':'.$raw,$secret);
$readCount=0;
$notSeen=static function(string $id)use(&$readCount):bool{++$readCount;return false;};
$inspect=static fn(string $body,array $hdr,string $key,string $work,string $conv,callable $seen,int $clock):array=>
    KiComMembraneSlackMpimIngress::inspect($body,$hdr,$key,$work,$conv,$seen,$clock);
$check=static function(array $r,string $code,string $name):void {
    mpimOk($r['code']===$code && !$r['action_authorized']
        && !$r['delivery_authorized'] && !$r['runtime_ack_verified']
        && !$r['communication_changed']
        && $r['message_trust']==='UNTRUSTED_EXTERNAL_INPUT',$name);
};
$good=$inspect($raw,$headers,$secret,$team,$channel,$notSeen,$now);
$check($good,'SIGNED_MPIM_METADATA_CANDIDATE',
    'Valid signed synthetic group DM is observation candidate, not action authority');
mpimOk(($good['evidence']['raw_sha256']??null)===hash('sha256',$raw)
    && $good['evidence']['deduplication_committed']===false
    && !isset($good['evidence']['text']),
    'Evidence binds raw payload digest without storing private message content or claiming dedup commit');
$check($inspect($raw,$headers,'',$team,$channel,$notSeen,$now),
    'STAGING_TRUST_CONFIGURATION_MISSING','Missing independent signing secret fails closed');
$check($inspect($raw,$headers,$secret,'',$channel,$notSeen,$now),
    'STAGING_TRUST_CONFIGURATION_MISSING','Missing workspace trust binding fails closed');
$check($inspect($raw,$headers,$secret,$team,'',$notSeen,$now),
    'STAGING_TRUST_CONFIGURATION_MISSING','Missing group conversation trust binding fails closed');
$check($inspect('',$headers,$secret,$team,$channel,$notSeen,$now),
    'PAYLOAD_SIZE_INVALID','Empty payload denied');
$check($inspect(str_repeat('A',1048577),$headers,$secret,$team,$channel,$notSeen,$now),
    'PAYLOAD_SIZE_INVALID','Oversized payload denied before parsing');
$check($inspect($raw,[],$secret,$team,$channel,$notSeen,$now),
    'SLACK_SIGNATURE_HEADERS_INVALID','Absent signing headers cannot authenticate Slack');
$bad=$headers;$bad['x-slack-signature']='v0='.str_repeat('0',64);
$check($inspect($raw,$bad,$secret,$team,$channel,$notSeen,$now),
    'SLACK_SIGNATURE_INVALID','Wrong HMAC rejected');
$bad=$headers;$bad['x-slack-request-timestamp']='1789826699';
$check($inspect($raw,$bad,$secret,$team,$channel,$notSeen,$now),
    'SLACK_SIGNATURE_INVALID','Changed timestamp with unchanged HMAC rejected');
$check($inspect($raw,$headers,$secret,$team,$channel,$notSeen,$now+301),
    'SLACK_TIMESTAMP_OUT_OF_WINDOW','Stale signed request rejected');
$check($inspect($raw,$headers,$secret,$team,$channel,$notSeen,$now-301),
    'SLACK_TIMESTAMP_OUT_OF_WINDOW','Future-dated signed request outside window rejected');
$check($inspect($raw.' ',$headers,$secret,$team,$channel,$notSeen,$now),
    'SLACK_SIGNATURE_INVALID','Body whitespace mutation invalidates raw signature');
$sign=static function(array $item)use($now,$secret):array{
    $bytes=json_encode($item,JSON_THROW_ON_ERROR);
    return [$bytes,[
        'x-slack-request-timestamp'=>(string)$now,
        'x-slack-signature'=>'v0='.hash_hmac('sha256','v0:'.$now.':'.$bytes,$secret)
    ]];
};
[$bytes,$h]=$sign(['type'=>'event_callback','team_id'=>$team,'event_id'=>'Ev123456789_TEST','event'=>'not-object']);
$check($inspect($bytes,$h,$secret,$team,$channel,$notSeen,$now),
    'SLACK_ENVELOPE_UNEXPECTED','Signed malformed event envelope denied');
$bad=$payload;$bad['team_id']='T_OTHER';[$bytes,$h]=$sign($bad);
$check($inspect($bytes,$h,$secret,$team,$channel,$notSeen,$now),
    'SLACK_ENVELOPE_UNEXPECTED','Signed event for other workspace denied');
$bad=$payload;$bad['event']['channel']='G_DIFFERENT';[$bytes,$h]=$sign($bad);
$check($inspect($bytes,$h,$secret,$team,$channel,$notSeen,$now),
    'SLACK_MPIM_EVENT_NOT_ADMITTED','Signed event for other group denied');
$bad=$payload;$bad['event']['channel_type']='channel';[$bytes,$h]=$sign($bad);
$check($inspect($bytes,$h,$secret,$team,$channel,$notSeen,$now),
    'SLACK_MPIM_EVENT_NOT_ADMITTED','Signed public channel event denied');
$bad=$payload;$bad['event']['type']='app_mention';[$bytes,$h]=$sign($bad);
$check($inspect($bytes,$h,$secret,$team,$channel,$notSeen,$now),
    'SLACK_MPIM_EVENT_NOT_ADMITTED','Native app_mention routed to original KiCom, not group-DM staging');
$bad=$payload;$bad['event']['bot_id']='B_SYNTHETIC';[$bytes,$h]=$sign($bad);
$check($inspect($bytes,$h,$secret,$team,$channel,$notSeen,$now),
    'SLACK_MPIM_EVENT_NOT_ADMITTED','Bot messages are not forwarded into an echo loop');
$bad=$payload;$bad['event']['subtype']='message_changed';[$bytes,$h]=$sign($bad);
$check($inspect($bytes,$h,$secret,$team,$channel,$notSeen,$now),
    'SLACK_MPIM_EVENT_NOT_ADMITTED','Edited or subtype messages do not become new delivery');
$bad=$payload;$bad['event_id']='bad event id';[$bytes,$h]=$sign($bad);
$check($inspect($bytes,$h,$secret,$team,$channel,$notSeen,$now),
    'SLACK_EVENT_ID_INVALID','Signed malformed event ID rejected before dedup');
$already=static fn(string $id):bool=>true;
$check($inspect($raw,$headers,$secret,$team,$channel,$already,$now),
    'SLACK_EVENT_ALREADY_SEEN_OR_UNKNOWN','Replay-dedup hint rejects observed duplicate');
$failing=static function(string $id):bool{throw new RuntimeException('dedup backend down');};
$check($inspect($raw,$headers,$secret,$team,$channel,$failing,$now),
    'SLACK_DEDUPLICATION_UNAVAILABLE','Unreachable dedup backend never fail-opens admission');
mpimOk($readCount===2,
    'Invalid and unauthenticated events never consult replay store; only valid cases do');
echo "MEMBRANE_MPIM_INGRESS_TESTS_PASSED=$checks\n";
