<?php
declare(strict_types=1);
require_once __DIR__.'/KiComMembraneEventReceipt.php';
$n=0;
function receiptOk(bool $pass,string $label):void {
    global $n; ++$n;
    if (!$pass) throw new RuntimeException('FAIL '.$label);
    echo "PASS $label\n";
}
function codeIs(array $r,string $code):bool {
    return ($r['code']??null)===$code
        && ($r['action_authorized']??null)===false
        && ($r['delivery_authorized']??null)===false
        && ($r['automatic_replay_allowed']??null)===false
        && ($r['runtime_ack_verified']??null)===false;
}
$root=sys_get_temp_dir().'/kicom-membrane-receipts-'.bin2hex(random_bytes(7));
mkdir($root,0700);
$path=$root.'/receipts.sqlite';
$event=hash('sha256','synthetic-event-1');
$raw=hash('sha256','synthetic-body-1');
try {
    new KiComMembraneEventReceipt($root.'/other.sqlite');
    throw new RuntimeException('FAIL unexpected path accepted');
} catch (InvalidArgumentException $e) {
    receiptOk(true,'Refuse arbitrary database outside named isolated staging area');
}
$db=new KiComMembraneEventReceipt($path);
receiptOk(codeIs($db->inspect($event),'RECEIPT_NOT_FOUND'),
    'Fresh receipt database contains no synthetic event');
receiptOk(codeIs($db->reserve('invalid',$raw),'INVALID_RECEIPT_IDENTITY'),
    'Malformed event hash rejected');
receiptOk(codeIs($db->reserve($event,'invalid'),'INVALID_RECEIPT_IDENTITY'),
    'Malformed raw payload hash rejected');
$first=$db->reserve($event,$raw);
receiptOk(codeIs($first,'NEW_DURABLE_RECEIPT'),
    'New event reserves persistent identity without dispatch permission');
receiptOk(codeIs($db->inspect($event),'RECEIPT_NEEDS_RECONCILIATION'),
    'First reservation remains explicitly unresolved');
receiptOk(codeIs($db->reserve($event,$raw),
    'EXISTING_RECEIPT_REQUIRES_RECONCILIATION'),
    'Identical provider retry cannot produce a new dispatch candidate');
receiptOk(codeIs($db->reserve($event,hash('sha256','tampered-body')),
    'EVENT_ID_HASH_COLLISION'),
    'Same event ID with different raw bytes is an identity conflict');
unset($db);
$reopen=new KiComMembraneEventReceipt($path);
receiptOk(codeIs($reopen->reserve($event,$raw),
    'EXISTING_RECEIPT_REQUIRES_RECONCILIATION'),
    'Restarted process retains persistent unresolved event');

$crashId=hash('sha256','synthetic-crash-reservation');
$crashRaw=hash('sha256','synthetic-crash-body');
$cmd=[PHP_BINARY,__DIR__.'/test-receipt-worker.php',$path,$crashId,$crashRaw];
$desc=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$p=proc_open($cmd,$desc,$pipes);
if (!is_resource($p)) throw new RuntimeException('Cannot launch isolated worker');
foreach($pipes as $stream) fclose($stream);
$exit=proc_close($p);
receiptOk($exit===0,'Separate worker terminated immediately after SQLite reservation');
receiptOk(codeIs($reopen->reserve($crashId,$crashRaw),
    'EXISTING_RECEIPT_REQUIRES_RECONCILIATION'),
    'Post-crash retry cannot invoke or automatically replay an external action');

$lock=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$lock->exec('PRAGMA busy_timeout=500');
$lock->exec('BEGIN IMMEDIATE');
$lockedId=hash('sha256','event-during-write-lock');
$blocked=$reopen->reserve($lockedId,$raw);
receiptOk(codeIs($blocked,'RECEIPT_DATABASE_UNAVAILABLE'),
    'Concurrent writer lock fails closed rather than accepting an uncommitted event');
$lock->rollBack();
receiptOk(codeIs($reopen->inspect($lockedId),'RECEIPT_NOT_FOUND'),
    'Failed locked reservation never partially inserts an event');
receiptOk(codeIs($reopen->reserve($lockedId,$raw),'NEW_DURABLE_RECEIPT'),
    'After lock clears a never-committed event may be reserved once');
receiptOk(codeIs($reopen->reserve($lockedId,$raw),
    'EXISTING_RECEIPT_REQUIRES_RECONCILIATION'),
    'Second caller does not obtain a second reservation');

$reflection=new ReflectionClass(KiComMembraneEventReceipt::class);
$methods=array_map(static fn(ReflectionMethod $m):string=>$m->name,
    $reflection->getMethods(ReflectionMethod::IS_PUBLIC));
sort($methods);
receiptOk($methods===['__construct','inspect','reserve'],
    'Receipt exposes no Slack sender, acknowledgement grant, requeue, delete or replay method');
$pdo=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$rows=$pdo->query('SELECT event_hash,raw_hash,state FROM receipts ORDER BY event_hash')->fetchAll();
receiptOk(count($rows)===3
    && array_unique(array_column($rows,'state'))===['NEEDS_RECONCILIATION'],
    'Every accepted reservation remains in the durable unresolved ledger');
echo "MEMBRANE_RECEIPT_TESTS_PASSED=$n\n";
