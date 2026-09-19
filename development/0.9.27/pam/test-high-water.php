<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPamHighWaterVerifier.php';
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "PDO_SQLITE_REQUIRED\n"); exit(2); }
$checks=0;
function highOk(bool $result,string $label):void {
    global $checks;
    ++$checks;
    if (!$result) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
$root=sys_get_temp_dir().'/kicom-highwater-'.bin2hex(random_bytes(7));
if (!mkdir($root,0700)) throw new RuntimeException('Unable to prepare test root');
$source=new PDO('sqlite:'.$root.'/source.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$source->exec('PRAGMA journal_mode=WAL');
$source->exec('CREATE TABLE records(v INTEGER NOT NULL)');
$seq=new KiComPamSnapshotSequencer($root);
$counter=0;
$writer=static function() use ($root,$source,&$counter):array {
    ++$counter;
    $source->exec('INSERT INTO records(v) VALUES('.$counter.')');
    $id=gmdate('YmdHis').'-'.sprintf('%010x',$counter);
    $file=$root.'/snapshot-'.$id.'.sqlite';
    $source->exec('VACUUM INTO '.$source->quote($file));
    $sha=hash_file('sha256',$file);
    file_put_contents($root.'/snapshot-'.$id.'.json',
        json_encode(['id'=>$id,'file_name'=>basename($file),'sha256'=>$sha],JSON_THROW_ON_ERROR));
    return ['ok'=>true,'id'=>$id,'sha256'=>$sha];
};
$claim=static function(array $result) use ($root):array {
    return [
        'sequence'=>$result['sequence'],
        'snapshot_id'=>$result['snapshot_id'],
        'snapshot_sha256'=>$result['snapshot_sha256'],
        'entry_sha256'=>hash_file('sha256',$root.'/pam-sequence/entry-'.sprintf('%010d',$result['sequence']).'.json')
    ];
};
$a=$seq->create($writer);
$b=$seq->create($writer);
highOk($a['ok'] && $b['ok'] && $b['sequence']===2,'Two independent native copies sequenced');
$older=$claim($a);$anchor=$claim($b);
$inspect=static fn(?array $a):array=>KiComPamHighWaterVerifier::inspect($seq,$root,$a);
highOk($inspect(null)['code']==='INDEPENDENT_HIGH_WATER_ANCHOR_MISSING',
    'Missing independent claim fails closed even with intact local journal');
$valid=$inspect($anchor);
highOk($valid['ok'] && $valid['sequence']===2
    && $valid['snapshot_id']===$b['snapshot_id'],
    'Correct externally supplied high-water claim matches full local inventory');
highOk($valid['independent_anchor_authenticated_here']===false
    && !$valid['restore_permitted'] && !$valid['automatic_recovery_permitted'],
    'Matching supplied claim is not authentication or restoration authority');
highOk($inspect($older)['code']==='HIGH_WATER_ROLLBACK_OR_DIVERGENCE',
    'Stale lower high-water claim never silently rewinds recovery');
$missingKey=$anchor;unset($missingKey['entry_sha256']);
highOk($inspect($missingKey)['code']==='HIGH_WATER_ANCHOR_FORMAT_INVALID',
    'Incomplete high-water evidence rejected');
$forged=$anchor;$forged['entry_sha256']=str_repeat('0',64);
highOk($inspect($forged)['code']==='HIGH_WATER_ENTRY_HASH_MISMATCH',
    'Hash of exact final ledger entry is bound to supplied high-water claim');
$extra=$root.'/snapshot-'.gmdate('YmdHis').'-ffffffffff.sqlite';
file_put_contents($extra,'unregistered');
highOk($inspect($anchor)['code']==='SNAPSHOT_INVENTORY_INCOMPLETE',
    'Orphan snapshot bytes without metadata invalidate complete recovery inventory');
unlink($extra);
$entry=$root.'/pam-sequence/entry-0000000002.json';
$meta=$root.'/snapshot-'.$b['snapshot_id'].'.json';
$file=$root.'/snapshot-'.$b['snapshot_id'].'.sqlite';
$entryBytes=file_get_contents($entry);
$metaBytes=file_get_contents($meta);
$fileBytes=file_get_contents($file);
unlink($entry);unlink($meta);unlink($file);
highOk($seq->inspect()['ok'] && $seq->inspect()['sequence']===1,
    'Local append-only-looking journal can be truncated with matching last files');
highOk($inspect($anchor)['code']==='HIGH_WATER_ROLLBACK_OR_DIVERGENCE',
    'Previously trusted external high-water claim detects complete local truncation');
file_put_contents($entry,$entryBytes);file_put_contents($meta,$metaBytes);file_put_contents($file,$fileBytes);
highOk($inspect($anchor)['ok'],'Restoring exact bytes recovers identity without editing claim');
$entryRaw=file_get_contents($entry);
file_put_contents($entry,substr($entryRaw,0,-3));
highOk($inspect($anchor)['code']==='LOCAL_SEQUENCE_SEQUENCE_LEDGER_UNTRUSTED',
    'Interrupted partial ledger publication fails closed');
file_put_contents($entry,$entryRaw);
$bad=$anchor;$bad['sequence']='2';
highOk($inspect($bad)['code']==='HIGH_WATER_ANCHOR_FORMAT_INVALID',
    'Numeric-string sequence cannot bypass exact format');
echo "PAM_HIGH_WATER_TESTS_PASSED=$checks\n";
