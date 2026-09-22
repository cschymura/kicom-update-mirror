<?php
declare(strict_types=1);
require __DIR__.'/KiComEngramFts5Index.php';
$n=0; function ok($v,$m){global $n;if(!$v)throw new RuntimeException('FAIL '.$m);$n++;echo "PASS $m\n";}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE engram_revisions(subject TEXT,namespace TEXT,id TEXT,revision INTEGER,body TEXT,entry_state TEXT)");
$rows=[
 ['owner-a','project','a',1,'Night development should continue autonomously without repeated questions.','active'],
 ['owner-a','project','a',2,'Night development proceeds autonomously and preserves verified checkpoints.','active'],
 ['owner-a','project','b',1,'OAuth write consent must remain separate from read access.','active'],
 ['owner-a','project','c',1,'This withdrawn memory must never be indexed.','withdrawn'],
 ['owner-b','project','d',1,'Night development belongs to another owner.','active'],
 ['owner-a','other','e',1,'Night development belongs to another namespace.','active']];
$i=$db->prepare('INSERT INTO engram_revisions VALUES(?,?,?,?,?,?)');foreach($rows as $r)$i->execute($r);
$x=new KiComEngramFts5Index($db);$cap=$x->capability();ok(isset($cap['fts5']),'capability reported');
if(!$cap['fts5']){echo "FTS5_UNAVAILABLE_CLEAN_FALLBACK\n";exit(0);} 
$x->migrateDisabled();$st=$x->status();ok($st['prepared']===true && $st['enabled']===false,'index prepared but cannot self-enable');
ok($x->rebuild()===4,'rebuild indexes only latest active canonical rows');
$r=$x->search('owner-a','project','autonomously');ok(count($r)===1 && $r[0]['id']==='a' && (int)$r[0]['revision']===2,'latest revision lexical hit');
ok(count($x->search('owner-b','project','Night'))===1,'owner-b isolated result');ok(count($x->search('owner-a','project','Night'))===1,'owner-a cannot see owner-b');
ok(count($x->search('owner-a','other','Night'))===1,'namespace isolated result');ok(count($x->search('owner-a','project','withdrawn'))===0,'withdrawn canonical row excluded');
$r=$x->search('owner-a','project','OAuth OR autonomously');ok(count($r)===2 && $r[0]['rank']!==null,'ranking returns bounded matches');
$semantic=$x->search('owner-a','project','permission');ok(count($semantic)===0,'lexical FTS does not falsely claim semantic paraphrase recall');
$db->exec("INSERT INTO engram_revisions VALUES('owner-a','project','f',1,'Separate authorization is mandatory for mutations.','active')");
ok(count($x->search('owner-a','project','authorization'))===0,'index is explicit not trigger-driven');$x->rebuild();ok(count($x->search('owner-a','project','authorization'))===1,'reindex deterministically catches new canonical row');
$bad=false;try{$x->search('../owner','project','Night');}catch(InvalidArgumentException $e){$bad=true;}ok($bad,'invalid scope rejected');
$bad=false;try{$x->search('owner-a','project','Night',21);}catch(InvalidArgumentException $e){$bad=true;}ok($bad,'unbounded limit rejected');
echo "DEV71_FTS5_TESTS=$n\n";
