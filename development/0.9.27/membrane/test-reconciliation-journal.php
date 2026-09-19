<?php
declare(strict_types=1);
require_once __DIR__.'/KiComMembraneEventReceipt.php';
require_once __DIR__.'/KiComMembraneReconciliationJournal.php';
$tests=0;
function reconOk(bool $pass,string $label):void {
    global $tests; ++$tests;
    if (!$pass) throw new RuntimeException('FAIL '.$label);
    echo "PASS $label\n";
}
function reconIs(array $r,string $code):bool {
    return ($r['code'] ?? null) === $code
        && ($r['inspection_only'] ?? null) === true
        && ($r['independent_proof_authenticated_here'] ?? null) === false
        && ($r['effect_verified'] ?? null) === false
        && ($r['action_authorized'] ?? null) === false
        && ($r['delivery_authorized'] ?? null) === false
        && ($r['automatic_replay_allowed'] ?? null) === false
        && ($r['runtime_ack_verified'] ?? null) === false;
}
$root=sys_get_temp_dir().'/kicom-membrane-receipts-'.bin2hex(random_bytes(7));
mkdir($root,0700);
$path=$root.'/receipts.sqlite';
$id=hash('sha256','staging-reconciliation-event');
$raw=hash('sha256','staging-reconciliation-raw');
$effect=hash('sha256','untrusted-provider-effect-claim');
$absence=hash('sha256','untrusted-provider-absence-claim');
$ack=hash('sha256','untrusted-runtime-ack-claim');
try {
    new KiComMembraneReconciliationJournal($root.'/wrong.sqlite');
    throw new RuntimeException('FAIL unexpected path admitted');
} catch (InvalidArgumentException $e) {
    reconOk(true,'Journal cannot create arbitrary replacement database');
}
$receipts=new KiComMembraneEventReceipt($path);
$journal=new KiComMembraneReconciliationJournal($path);
reconOk(reconIs($journal->inspect($id),'RECEIPT_NOT_FOUND'),
    'Unregistered event cannot acquire external effect evidence');
reconOk(reconIs($journal->record('bad',$raw,$effect,'REMOTE_EFFECT_REPORTED'),
    'EVIDENCE_IDENTITY_INVALID'),'Malformed event hash rejected');
reconOk(reconIs($journal->record($id,$raw,'bad','REMOTE_EFFECT_REPORTED'),
    'EVIDENCE_IDENTITY_INVALID'),'Malformed observation hash rejected');
reconOk(reconIs($journal->record($id,$raw,$effect,'UNKNOWN'),
    'EVIDENCE_KIND_INVALID'),'Unknown effect claims rejected');
reconOk(reconIs($journal->record($id,$raw,$effect,'REMOTE_EFFECT_REPORTED'),
    'RECEIPT_MISSING_OR_DIVERGENT'),'Evidence cannot be added without matching receipt');
reconOk(($receipts->reserve($id,$raw)['code']??null)==='NEW_DURABLE_RECEIPT',
    'Synthetic event reserved in isolated append-only intake');
reconOk(reconIs($journal->inspect($id),'NO_EXTERNAL_EVIDENCE_RECORDED'),
    'Unresolved receipt with no observation remains unverified');
reconOk(reconIs($journal->record($id,hash('sha256','different raw'),$effect,
    'REMOTE_EFFECT_REPORTED'),'RECEIPT_MISSING_OR_DIVERGENT'),
    'Claim about wrong raw bytes cannot attach to existing event');
reconOk(reconIs($journal->record($id,$raw,$effect,'REMOTE_EFFECT_REPORTED'),
    'EVIDENCE_RECORDED_UNVERIFIED'),
    'Remote-effect claim is recorded but neither authenticated nor executed');
reconOk(reconIs($journal->record($id,$raw,$effect,'REMOTE_EFFECT_REPORTED'),
    'EVIDENCE_ALREADY_RECORDED_UNVERIFIED'),
    'Identical provider retry does not duplicate an evidence row');
reconOk(reconIs($journal->record($id,$raw,$effect,'REMOTE_EFFECT_ABSENCE_REPORTED'),
    'EVIDENCE_ID_REUSED_FOR_DIFFERENT_CLAIM'),
    'Evidence ID cannot be re-used with a contradictory meaning');
reconOk(reconIs($journal->inspect($id),'EXTERNAL_CLAIMS_REQUIRE_INDEPENDENT_REVIEW'),
    'Single unverified effect report is not remote-delivery proof');
reconOk(reconIs($journal->record($id,$raw,$ack,'KICOM_ACK_REPORTED'),
    'EVIDENCE_RECORDED_UNVERIFIED'),
    'Unverified KiCom ACK claim never establishes runtime ACK');
reconOk(reconIs($journal->record($id,$raw,$absence,'REMOTE_EFFECT_ABSENCE_REPORTED'),
    'EVIDENCE_RECORDED_UNVERIFIED'),
    'Contradictory observation must be kept rather than overwriting first claim');
reconOk(reconIs($journal->inspect($id),'CONFLICTING_EFFECT_CLAIMS_REQUIRE_REVIEW'),
    'Contradictory delivery and absence claims require independent investigation');
unset($journal,$receipts);
$reopened=new KiComMembraneReconciliationJournal($path);
reconOk(reconIs($reopened->inspect($id),'CONFLICTING_EFFECT_CLAIMS_REQUIRE_REVIEW'),
    'Conflicting evidence survives PHP process restart');
$read=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$rows=$read->query('SELECT observation_hash,kind FROM reconciliation_observations
    ORDER BY observation_hash')->fetchAll(PDO::FETCH_ASSOC);
reconOk(count($rows)===3 && !in_array('text',array_keys($rows[0]),true),
    'Only hashes and claim kinds persisted, no message body, secrets or destinations');
$states=$read->query('SELECT state FROM receipts')->fetchAll(PDO::FETCH_COLUMN);
reconOk($states===['NEEDS_RECONCILIATION'],
    'Evidence observations never mark intake completed or permit automatic replay');
$public=array_map(static fn(ReflectionMethod $m):string=>$m->name,
    (new ReflectionClass(KiComMembraneReconciliationJournal::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC));
sort($public);
reconOk($public===['__construct','inspect','record'],
    'Journal exposes no Slack sender, release gate, resolver, deletion or replay API');
echo "MEMBRANE_RECONCILIATION_TESTS_PASSED=$tests\n";
