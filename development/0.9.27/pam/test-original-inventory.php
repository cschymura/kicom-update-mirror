<?php
declare(strict_types=1);
// Unit test substitutes ONLY the trusted path function, in a disposable dir.
$root = sys_get_temp_dir().'/kicom-original-inventory-'.bin2hex(random_bytes(7));
if (!mkdir($root,0700)) throw new RuntimeException('Cannot create isolated dir');
$dir = $root.'/sqlite';
mkdir($dir,0700);
function kicomSqliteFile(): string {
    global $dir;
    return $dir.'/kicom.sqlite';
}
require_once __DIR__.'/KiComPamOriginalInventory.php';
$n=0;
function invOk(bool $yes,string $name):void {
    global $n;
    ++$n;
    if (!$yes) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name.PHP_EOL;
}
$missing=KiComPamOriginalInventory::capture();
invOk(!$missing['ok'] && $missing['code']==='ORIGINAL_DB_MISSING',
    'No database is an explicit failure, not an empty recovery baseline');
$db=kicomSqliteFile();
file_put_contents($db,'non-healthy-original-db-bytes');
$one=KiComPamOriginalInventory::capture();
invOk($one['ok'] && $one['files']['db']['sha256']===hash_file('sha256',$db),
    'Read-only DB fingerprint allows preservation of even a damaged original');
invOk(!$one['files']['wal']['present'] && !$one['files']['shm']['present'],
    'Absent WAL and SHM are explicitly recorded');
invOk(KiComPamOriginalInventory::unchanged($one,KiComPamOriginalInventory::capture())['ok'],
    'Two unchanged fingerprints compare equal without restore authority');
invOk($one['inspection_only']===true && $one['restore_permitted']===false,
    'Original file fingerprint never grants recovery permission');
file_put_contents($db.'-wal','pending transaction A');
file_put_contents($db.'-shm','index A');
$withJournal=KiComPamOriginalInventory::capture();
invOk($withJournal['ok'] && $withJournal['files']['wal']['present']
    && $withJournal['files']['shm']['present'],
    'Original DB, WAL and SHM are independently accounted for');
invOk(KiComPamOriginalInventory::unchanged($one,$withJournal)['code']
    ==='ORIGINAL_FILESET_CHANGED_WAL',
    'New WAL after baseline invalidates unchanged evidence');
file_put_contents($db.'-wal','pending transaction B');
invOk(KiComPamOriginalInventory::unchanged($withJournal,
    KiComPamOriginalInventory::capture())['code']==='ORIGINAL_FILESET_CHANGED_WAL',
    'Same-size WAL mutation is detected by content hash');
file_put_contents($db.'-wal','pending transaction A');
file_put_contents($db.'-shm','index B');
invOk(KiComPamOriginalInventory::unchanged($withJournal,
    KiComPamOriginalInventory::capture())['code']==='ORIGINAL_FILESET_CHANGED_SHM',
    'Changed SHM content is separately detected');
file_put_contents($db.'-shm','index A');
file_put_contents($db,'non-healthy-original-db-byteZ');
invOk(KiComPamOriginalInventory::unchanged($withJournal,
    KiComPamOriginalInventory::capture())['code']==='ORIGINAL_FILESET_CHANGED_DB',
    'Original DB replacement blocks a previous fingerprint');
invOk(KiComPamOriginalInventory::unchanged([], $withJournal)['code']
    ==='ORIGINAL_FINGERPRINT_INCOMPLETE',
    'Absent baseline cannot be mistaken for unchanged originals');
unlink($db.'-wal');
symlink($db,$db.'-wal');
invOk(KiComPamOriginalInventory::capture()['code']==='ORIGINAL_FILE_UNTRUSTED_WAL',
    'Symlinked WAL cannot substitute for an original journal');
unlink($db.'-wal');
unlink($db);
mkdir($db);
invOk(KiComPamOriginalInventory::capture()['code']==='ORIGINAL_FILE_UNTRUSTED_DB',
    'A directory in place of the DB is rejected');
echo "PAM_ORIGINAL_INVENTORY_TESTS_PASSED=$n\n";
