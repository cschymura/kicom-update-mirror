<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPamSnapshotSequencer.php';
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "PDO_SQLITE_REQUIRED\n"); exit(2); }
$n=0;
function seqOk(bool $condition, string $name): void {
    global $n; ++$n;
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ' . $name . PHP_EOL;
}
$root=sys_get_temp_dir().'/kicom-pam-sequence-'.bin2hex(random_bytes(6));
if (!mkdir($root,0700)) throw new RuntimeException('Cannot make isolated test directory');
$source=new PDO('sqlite:'.$root.'/source.sqlite');
$source->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$source->exec('CREATE TABLE data(value INTEGER NOT NULL)');
$seq=new KiComPamSnapshotSequencer($root);
$old=$seq->inspect();
seqOk($old['code']==='NO_SEQUENCED_SNAPSHOTS','Empty ledger has no recovery authority');
$writer=static function() use ($root,$source): array {
    static $i=0;
    ++$i;
    $id=gmdate('YmdHis').'-'.sprintf('%010x',$i);
    $file=$root.'/snapshot-'.$id.'.sqlite';
    $source->exec('INSERT INTO data(value) VALUES('.$i.')');
    $source->exec('VACUUM INTO '.$source->quote($file));
    $sha=hash_file('sha256',$file);
    $meta=['id'=>$id,'created_at'=>gmdate('c'),'file_name'=>basename($file),'sha256'=>$sha,'health'=>'ok'];
    file_put_contents($root.'/snapshot-'.$id.'.json',json_encode($meta,JSON_THROW_ON_ERROR));
    return ['ok'=>true,'id'=>$id,'sha256'=>$sha];
};
$a=$seq->create($writer);
$b=$seq->create($writer);
seqOk($a['ok']&&$a['sequence']===1&&$b['ok']&&$b['sequence']===2,'Serializes two snapshots without guessing randomized suffix order');
seqOk($a['snapshot_id']!==$b['snapshot_id'],'Native snapshot IDs remain distinct');
$r=$seq->inspect();
seqOk($r['ok']&&$r['sequence']===2&&$r['snapshot_id']===$b['snapshot_id'],
    'Read-only selection follows actual protected writer order');
seqOk($r['restore_permitted']===false&&$r['inspection_only']===true,
    'Selection cannot authorize a restore');
$restarted=new KiComPamSnapshotSequencer($root);
seqOk($restarted->inspect()['snapshot_id']===$b['snapshot_id'],
    'Monotonic ledger persists across caller restart');
$broken=$restarted->create(static fn():array => ['ok'=>false,'code'=>'FAILED']);
seqOk(!$broken['ok']&&$broken['code']==='SNAPSHOT_NATIVE_CREATE_FAILED'
    && $restarted->inspect()['sequence']===2,'Failed writer cannot advance ledger');
$native=$writer(); // Simulated crash after native write, before journal publication.
seqOk($seq->inspect()['code']==='UNSEQUENCED_NATIVE_SNAPSHOT',
    'Unjournaled snapshot from interrupted write is quarantined, never silently ordered');
$beforeLegacy = count(glob($root.'/snapshot-*.json') ?: []);
$writerCalled = false;
$refused = $seq->create(static function () use (&$writerCalled):array {
    $writerCalled = true;
    throw new RuntimeException('Must not invoke writer on legacy/orphan inventory');
});
seqOk(!$refused['ok'] && $refused['code']==='SNAPSHOT_LEGACY_INVENTORY_REQUIRES_REVIEW'
    && !$writerCalled
    && count(glob($root.'/snapshot-*.json') ?: []) === $beforeLegacy,
    'Orphan detected before writer invocation and existing backups remain intact');

// Recreate a clean separate ledger fixture; leave crashed state intact for auditing.
$other=sys_get_temp_dir().'/kicom-pam-sequence-fresh-'.bin2hex(random_bytes(6));
mkdir($other,0700);
$clean=new KiComPamSnapshotSequencer($other);
$writer2=static function() use($other,$source):array {
    $id=gmdate('YmdHis').'-bbbbbbbbbb';
    $file=$other.'/snapshot-'.$id.'.sqlite';
    $source->exec('VACUUM INTO '.$source->quote($file));
    $sha=hash_file('sha256',$file);
    file_put_contents($other.'/snapshot-'.$id.'.json',
        json_encode(['id'=>$id,'file_name'=>basename($file),'sha256'=>$sha],JSON_THROW_ON_ERROR));
    return ['ok'=>true,'id'=>$id,'sha256'=>$sha];
};
$c=$clean->create($writer2);
seqOk($c['ok']&&$clean->inspect()['ok'],'Independent new store can create trusted first journal entry');
$meta=$other.'/snapshot-'.$c['snapshot_id'].'.json';
$raw=file_get_contents($meta);
file_put_contents($meta,$raw."\nchanged");
seqOk($clean->inspect()['code']==='SEQUENCED_SNAPSHOT_VERIFICATION_FAILED',
    'Mutated manifest invalidates ledger without falling back');
file_put_contents($meta,$raw);
$entry=$other.'/pam-sequence/entry-0000000001.json';
$entryRaw=file_get_contents($entry);
file_put_contents($entry,'{}');
seqOk($clean->inspect()['code']==='SEQUENCE_LEDGER_UNTRUSTED',
    'Damaged append-only sequence entry fails closed');
file_put_contents($entry,$entryRaw);
seqOk($clean->inspect()['ok'],'Restoring exact ledger bytes restores verification');
$orphanBytes = $other.'/snapshot-'.gmdate('YmdHis').'-eeeeeeeeee.sqlite';
file_put_contents($orphanBytes,'bytes-without-any-manifest');
seqOk($clean->inspect()['code']==='UNSEQUENCED_NATIVE_SNAPSHOT',
    'A standalone unregistered SQLite backup with no JSON manifest blocks sequence selection');
unlink($orphanBytes);
seqOk($clean->inspect()['ok'], 'Exact registered backup inventory verifies after orphan bytes are removed');

$legacy = sys_get_temp_dir().'/kicom-pam-sequence-legacy-'.bin2hex(random_bytes(6));
mkdir($legacy,0700);
$oldId=gmdate('YmdHis').'-cccccccccc';
file_put_contents($legacy.'/snapshot-'.$oldId.'.json',
    json_encode(['id'=>$oldId,'sha256'=>str_repeat('b',64)],JSON_THROW_ON_ERROR));
file_put_contents($legacy.'/snapshot-'.$oldId.'.sqlite','intact-legacy-backup');
$legacySeq=new KiComPamSnapshotSequencer($legacy);
$legacyCalled=false;
$legacyResult=$legacySeq->create(static function () use (&$legacyCalled):array {
    $legacyCalled=true;
    return ['ok'=>false];
});
seqOk(!$legacyResult['ok'] && $legacyResult['code']==='SNAPSHOT_LEGACY_INVENTORY_REQUIRES_REVIEW'
    && !$legacyCalled,
    'Unsequenced pre-existing legacy backup blocks new sequence before native writer');
seqOk(file_get_contents($legacy.'/snapshot-'.$oldId.'.sqlite')==='intact-legacy-backup',
    'Fail-closed legacy inventory preserves exact pre-existing backup bytes');

echo "PAM_SEQUENCER_TESTS_PASSED=$n\n";
