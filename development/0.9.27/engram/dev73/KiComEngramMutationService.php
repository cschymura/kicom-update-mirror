<?php
declare(strict_types=1);
require_once __DIR__.'/../dev72/KiComEngramWriteGrant.php';

/** DEV-only mutation integration. No HTTP route and no production activation. */
final class KiComEngramMutationService {
    private PDO $db; private KiComEngramWriteGrant $grants;
    public function __construct(PDO $db, KiComEngramWriteGrant $grants) {
        $this->db=$db; $this->grants=$grants;
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys=ON');
        $db->exec('CREATE TABLE IF NOT EXISTS dev73_engrams(owner TEXT NOT NULL, namespace TEXT NOT NULL, id TEXT NOT NULL, revision INTEGER NOT NULL, body TEXT NOT NULL, state TEXT NOT NULL CHECK(state IN ("active","archived")), revision_hash TEXT NOT NULL, previous_hash TEXT NOT NULL, PRIMARY KEY(owner,namespace,id,revision))');
        $db->exec('CREATE TABLE IF NOT EXISTS dev73_mutations(owner TEXT NOT NULL, namespace TEXT NOT NULL, idempotency_key TEXT NOT NULL, operation TEXT NOT NULL, request_hash TEXT NOT NULL, result_json TEXT NOT NULL, grant_id TEXT NOT NULL, nonce TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(owner,namespace,idempotency_key), UNIQUE(owner,namespace,grant_id,nonce))');
        $db->exec('CREATE TABLE IF NOT EXISTS dev73_audit(seq INTEGER PRIMARY KEY AUTOINCREMENT, owner TEXT NOT NULL, namespace TEXT NOT NULL, operation TEXT NOT NULL, engram_id TEXT NOT NULL, revision INTEGER NOT NULL, grant_id TEXT NOT NULL, outcome TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    }
    public function mutate(string $grant,array $oauth,string $operation,array $input,string $idem,int $now):array {
        if(!preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$idem)) throw new InvalidArgumentException('invalid idempotency key');
        $verified=$this->grants->verify($grant,$oauth,$operation,$now);
        $owner=$verified['owner']; $ns=$verified['namespace'];
        $allowed=['engram_write'=>['body'],'engram_update'=>['id','body','expected_revision','expected_hash'],'engram_archive'=>['id','expected_revision','expected_hash']];
        $keys=array_keys($input); sort($keys); $expected=$allowed[$operation]; sort($expected); if($keys!==$expected) throw new InvalidArgumentException('invalid mutation shape');
        if(isset($input['body']) && (!is_string($input['body']) || $input['body']==='' || strlen($input['body'])>4096)) throw new InvalidArgumentException('invalid body');
        $reqHash=hash('sha256',json_encode([$operation,$input],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT operation,request_hash,result_json FROM dev73_mutations WHERE owner=? AND namespace=? AND idempotency_key=?'); $q->execute([$owner,$ns,$idem]); $old=$q->fetch(PDO::FETCH_ASSOC);
            if($old){ if(!hash_equals($old['request_hash'],$reqHash)||$old['operation']!==$operation) throw new RuntimeException('idempotency conflict'); $this->db->exec('COMMIT'); return json_decode($old['result_json'],true,8,JSON_THROW_ON_ERROR); }
            if($operation==='engram_write') { $id=bin2hex(random_bytes(16)); $rev=1; $prev=str_repeat('0',64); $state='active'; $body=$input['body']; }
            else {
                if(!is_string($input['id']) || !preg_match('/\A[a-f0-9]{32}\z/D',$input['id']) || !is_int($input['expected_revision']) || !is_string($input['expected_hash'])) throw new InvalidArgumentException('invalid concurrency input');
                $id=$input['id']; $q=$this->db->prepare('SELECT * FROM dev73_engrams WHERE owner=? AND namespace=? AND id=? ORDER BY revision DESC LIMIT 1'); $q->execute([$owner,$ns,$id]); $cur=$q->fetch(PDO::FETCH_ASSOC);
                if(!$cur || $cur['state']!=='active' || (int)$cur['revision']!==$input['expected_revision'] || !hash_equals($cur['revision_hash'],$input['expected_hash'])) throw new RuntimeException('revision conflict');
                $rev=(int)$cur['revision']+1; $prev=$cur['revision_hash']; $state=$operation==='engram_archive'?'archived':'active'; $body=$operation==='engram_archive'?$cur['body']:$input['body'];
            }
            $hash=hash('sha256',json_encode([$owner,$ns,$id,$rev,$body,$state,$prev],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $q=$this->db->prepare('INSERT INTO dev73_engrams VALUES(?,?,?,?,?,?,?,?)'); $q->execute([$owner,$ns,$id,$rev,$body,$state,$hash,$prev]);
            $result=['id'=>$id,'revision'=>$rev,'revision_hash'=>$hash,'state'=>$state]; $json=json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $q=$this->db->prepare('INSERT INTO dev73_mutations(owner,namespace,idempotency_key,operation,request_hash,result_json,grant_id,nonce) VALUES(?,?,?,?,?,?,?,?)'); $q->execute([$owner,$ns,$idem,$operation,$reqHash,$json,$verified['grant_id'],$verified['nonce']]);
            $q=$this->db->prepare('INSERT INTO dev73_audit(owner,namespace,operation,engram_id,revision,grant_id,outcome) VALUES(?,?,?,?,?,?,"committed")'); $q->execute([$owner,$ns,$operation,$id,$rev,$verified['grant_id']]);
            $this->db->exec('COMMIT'); return $result;
        } catch(Throwable $e){ if($this->db->inTransaction())$this->db->exec('ROLLBACK'); throw $e; }
    }
    public function current(string $owner,string $ns,string $id):?array { $q=$this->db->prepare('SELECT id,revision,body,state,revision_hash,previous_hash FROM dev73_engrams WHERE owner=? AND namespace=? AND id=? ORDER BY revision DESC LIMIT 1');$q->execute([$owner,$ns,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null; }
    public function auditCount():int { return (int)$this->db->query('SELECT count(*) FROM dev73_audit')->fetchColumn(); }
}