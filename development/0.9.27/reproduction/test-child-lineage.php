<?php
declare(strict_types=1);
require_once __DIR__.'/KiComChildLineage.php';
$n=0;
function t(bool $ok,string $why):void {global $n;++$n;if(!$ok)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}
$mother=sodium_crypto_sign_keypair();
$child=sodium_crypto_sign_keypair();
$other=sodium_crypto_sign_keypair();
$p=base64_encode(sodium_crypto_sign_publickey($mother));
$c=base64_encode(sodium_crypto_sign_publickey($child));
$o=base64_encode(sodium_crypto_sign_publickey($other));
$nonce=bin2hex(random_bytes(32));
$pkg='6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f';
$sig=base64_encode(sodium_crypto_sign_detached(
    KiComChildLineage::proofBytes($nonce,$p,$c,$pkg),sodium_crypto_sign_secretkey($child)));
$valid=KiComChildLineage::checkProof($nonce,$p,$c,$pkg,$sig);
t($valid['proof_valid'] && !$valid['native_genome_bound']
    && !$valid['external_action_authorized'] && !$valid['deployment_authorized'],
    'Child signs challenge, but native genome and deployment remain unbound');
$r=KiComChildLineage::candidateRecord($nonce,$p,$c,$pkg,$sig,'daughter-one','kicom-0.9.26-g25r3');
t($r['ok'] && $r['code']==='LAB_LINEAGE_CANDIDATE_ONLY'
    && !$r['native_genome_bound'] && !$r['recovery_bound']
    && !$r['independent_host_qualified'] && !$r['external_action_authorized'],
    'Candidate lineage is only a no-authority cryptographic proof');
t($r['parent_public_fingerprint']!==$r['child_public_fingerprint'],
    'Child and parent have separate public identities');
t(!KiComChildLineage::checkProof($nonce,$p,$o,$pkg,$sig)['proof_valid'],
    'Child proof cannot be substituted onto another public identity');
t(!KiComChildLineage::checkProof($nonce,$c,$p,$pkg,$sig)['proof_valid'],
    'Parent-child substitution does not validate a child signature');
t(!KiComChildLineage::checkProof(bin2hex(random_bytes(32)),$p,$c,$pkg,$sig)['proof_valid'],
    'Replay with different fresh nonce fails');
t(!KiComChildLineage::checkProof($nonce,$p,$c,hash('sha256','different-package'),$sig)['proof_valid'],
    'Different software package fails original child proof');
$forged=base64_encode(sodium_crypto_sign_detached(
    KiComChildLineage::proofBytes($nonce,$p,$c,$pkg),sodium_crypto_sign_secretkey($other)));
t(!KiComChildLineage::checkProof($nonce,$p,$c,$pkg,$forged)['proof_valid'],
    'Third-party signing key cannot replace daughter private key');
t(!KiComChildLineage::checkProof($nonce,$p,$p,$pkg,$sig)['proof_valid'],
    'Child cannot share identical public identity with mother');
t(!KiComChildLineage::candidateRecord($nonce,$p,$c,$pkg,$sig,'../../escape','kicom-0.9.26-g25r3')['ok'],
    'Label/path traversal rejected');
t(!KiComChildLineage::candidateRecord($nonce,$p,$c,$pkg,$sig,'daughter-one','kicom-0.9.26-g25r2')['ok'],
    'Historical genome cannot be silently promoted to current lineage');
t(!KiComChildLineage::checkProof($nonce,'not-base64',$c,$pkg,$sig)['proof_valid'],
    'Malformed public key fails closed');
$methods=array_map(static fn(ReflectionMethod $m)=>$m->name,
    (new ReflectionClass(KiComChildLineage::class))->getMethods(ReflectionMethod::IS_PUBLIC));
sort($methods);
t($methods===['candidateRecord','checkProof','proofBytes'],
    'Pure lineage module cannot spawn, send, install, mutate genomes or issue credentials');
echo "KICOM_CHILD_LINEAGE_TESTS_PASSED=$n\n";
