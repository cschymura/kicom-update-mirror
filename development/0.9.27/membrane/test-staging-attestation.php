<?php
declare(strict_types=1);
require_once __DIR__.'/KiComMembraneStagingAttestation.php';

$checks=0;
function attestOk(bool $ok, string $label): void {
    global $checks;
    ++$checks;
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
function attestIs(array $r, string $code): bool {
    return ($r['code']??null)===$code
        && ($r['inspection_only']??null)===true
        && ($r['independent_anchor_authenticated_here']??null)===false
        && ($r['slack_provider_effect_verified']??null)===false
        && ($r['kicom_runtime_ack_verified']??null)===false
        && ($r['action_authorized']??null)===false
        && ($r['delivery_authorized']??null)===false
        && ($r['automatic_replay_allowed']??null)===false;
}
if (!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('Isolated CI requires PHP sodium for Ed25519');
}
$pair=sodium_crypto_sign_keypair();
$private=sodium_crypto_sign_secretkey($pair);
$public=base64_encode(sodium_crypto_sign_publickey($pair));
$unrelatedPair=sodium_crypto_sign_keypair();
$unrelatedPublic=base64_encode(sodium_crypto_sign_publickey($unrelatedPair));
$now=1789826700;
$claim=[
    'version'=>'kicom-staging-attestation-v1',
    'key_id'=>'stage-attester-01',
    'workspace_id'=>'T_STAGE_ISOLATED',
    'conversation_id'=>'G_STAGE_ONLY',
    'event_hash'=>hash('sha256','event-stage'),
    'raw_hash'=>hash('sha256','raw-stage'),
    'nonce_hash'=>hash('sha256','challenge-stage'),
    'observation_hash'=>hash('sha256','synthetic-observation-stage'),
    'kind'=>'REMOTE_EFFECT_REPORTED',
    'observed_at'=>$now
];
$expected=[
    'key_id'=>$claim['key_id'],
    'workspace_id'=>$claim['workspace_id'],
    'conversation_id'=>$claim['conversation_id'],
    'event_hash'=>$claim['event_hash'],
    'raw_hash'=>$claim['raw_hash'],
    'nonce_hash'=>$claim['nonce_hash'],
    'observation_hash'=>$claim['observation_hash'],
    'allowed_kinds'=>['REMOTE_EFFECT_REPORTED','KICOM_ACK_REPORTED']
];
$sign=static fn(array $input):string=>base64_encode(
    sodium_crypto_sign_detached(
        KiComMembraneStagingAttestation::signingBytes($input),$private)
);
$signature=$sign($claim);
$verify=static fn(array $c,string $sig,string $pk,array $ctx,int $t):array=>
    KiComMembraneStagingAttestation::verify($c,$sig,$pk,$ctx,$t);
$good=$verify($claim,$signature,$public,$expected,$now);
attestOk(attestIs($good,'SIGNED_STAGING_CLAIM_INTEGRITY_VERIFIED')
    && $good['signature_valid']===true,
    'Valid synthetic Ed25519 attestation proves statement integrity ONLY');
attestOk(($good['claim_metadata']['event_hash']??null)===$claim['event_hash']
    && count($good['claim_metadata'])===4
    && !isset($good['claim_metadata']['workspace_id'])
    && !isset($good['claim_metadata']['conversation_id']),
    'Success exposes no raw message, destination or staging secret');
$bad=$claim;$bad['raw_hash']=hash('sha256','different-payload');
attestOk(attestIs($verify($bad,$signature,$public,$expected,$now),
    'ATTESTATION_CONTEXT_MISMATCH'),'Changed raw event hash does not match trusted receipt');
$bad=$claim;$bad['kind']='REMOTE_EFFECT_ABSENCE_REPORTED';
attestOk(attestIs($verify($bad,$signature,$public,$expected,$now),
    'ATTESTATION_KIND_NOT_ALLOWED'),'Disallowed absence claim cannot become verified effect');
$bad=$claim;$bad['kind']='KICOM_ACK_REPORTED';
attestOk(attestIs($verify($bad,$signature,$public,$expected,$now),
    'ATTESTATION_SIGNATURE_INVALID'),'Changed claim kind fails original signature');
$bad=$claim;$bad['observation_hash']=hash('sha256','wrong-receipt');
attestOk(attestIs($verify($bad,$sign($bad),$public,$expected,$now),
    'ATTESTATION_CONTEXT_MISMATCH'),'Signed observation about another receipt cannot be rebound');
$bad=$claim;$bad['nonce_hash']=hash('sha256','old-challenge');
attestOk(attestIs($verify($bad,$sign($bad),$public,$expected,$now),
    'ATTESTATION_CONTEXT_MISMATCH'),'Old challenge cannot satisfy new unique probe');
$bad=$claim;$bad['conversation_id']='G_WRONG_GROUP';
attestOk(attestIs($verify($bad,$sign($bad),$public,$expected,$now),
    'ATTESTATION_CONTEXT_MISMATCH'),'Signed receipt for another group cannot cross boundary');
$bad=$claim;$bad['workspace_id']='T_WRONG_WORKSPACE';
attestOk(attestIs($verify($bad,$sign($bad),$public,$expected,$now),
    'ATTESTATION_CONTEXT_MISMATCH'),'Signed receipt for another workspace cannot cross boundary');
$bad=$claim;$bad['key_id']='stage-unknown';
attestOk(attestIs($verify($bad,$sign($bad),$public,$expected,$now),
    'ATTESTATION_CONTEXT_MISMATCH'),'Untrusted signer identity rejected by pinned key ID');
attestOk(attestIs($verify($claim,$signature,$unrelatedPublic,$expected,$now),
    'ATTESTATION_SIGNATURE_INVALID'),'Valid signature with wrong verification key rejected');
attestOk(attestIs($verify($claim,'not_base64',$public,$expected,$now),
    'ATTESTATION_KEY_OR_SIGNATURE_INVALID'),'Malformed base64 signature rejected');
attestOk(attestIs($verify($claim,$signature,'', $expected,$now),
    'ATTESTATION_KEY_OR_SIGNATURE_INVALID'),'Missing protected verification key fails closed');
attestOk(attestIs($verify($claim,$signature,$public,$expected,$now+301),
    'ATTESTATION_TIME_WINDOW_INVALID'),'Expired observation outside five-minute window rejected');
attestOk(attestIs($verify($claim,$signature,$public,$expected,$now-301),
    'ATTESTATION_TIME_WINDOW_INVALID'),'Future observation outside time window rejected');
$bad=$claim;$bad['observed_at']='1789826700';
attestOk(attestIs($verify($bad,$signature,$public,$expected,$now),
    'ATTESTATION_FORMAT_INVALID'),'Timestamp string substitution cannot alter canonical representation');
$bad=$claim;$bad['extra']='action_authorized=true';
attestOk(attestIs($verify($bad,$signature,$public,$expected,$now),
    'ATTESTATION_FORMAT_INVALID'),'Injected unrecognized authority field rejected');
$bad=['kind'=>$claim['kind']]+$claim;
attestOk(attestIs($verify($bad,$signature,$public,$expected,$now),
    'ATTESTATION_FORMAT_INVALID'),'Reordered canonical fields rejected');
$bad=$claim;$bad['event_hash']='not-a-digest';
attestOk(attestIs($verify($bad,$signature,$public,$expected,$now),
    'ATTESTATION_FORMAT_INVALID'),'Malformed event identity rejected');
$ctx=$expected;unset($ctx['raw_hash']);
attestOk(attestIs($verify($claim,$signature,$public,$ctx,$now),
    'ATTESTATION_CONTEXT_MISMATCH'),'Missing independent receipt context rejected');
$ctx=$expected;$ctx['allowed_kinds']=['REMOTE_EFFECT_ABSENCE_REPORTED'];
attestOk(attestIs($verify($claim,$signature,$public,$ctx,$now),
    'ATTESTATION_KIND_NOT_ALLOWED'),'Attestation cannot promote kind beyond configured allowlist');
$ctx=$expected;$ctx['event_hash']=hash('sha256','different-event');
attestOk(attestIs($verify($claim,$signature,$public,$ctx,$now),
    'ATTESTATION_CONTEXT_MISMATCH'),'Valid signature for unrelated event is insufficient');
$otherKeypair=sodium_crypto_sign_keypair();
$forged=base64_encode(sodium_crypto_sign_detached(
    KiComMembraneStagingAttestation::signingBytes($claim),
    sodium_crypto_sign_secretkey($otherKeypair)
));
attestOk(attestIs($verify($claim,$forged,$public,$expected,$now),
    'ATTESTATION_SIGNATURE_INVALID'),'Attacker-signed claim rejected under protected public key');
$methods=array_map(static fn(ReflectionMethod $m):string=>$m->name,
    (new ReflectionClass(KiComMembraneStagingAttestation::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC));
sort($methods);
attestOk($methods===['signingBytes','verify'],
    'Verifier has no attestation signer, Slack sender, replay or action method');
echo "MEMBRANE_ATTESTATION_TESTS_PASSED=$checks\n";
