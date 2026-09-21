<?php
declare(strict_types=1);

/** DEV-only transport contract. No HTTP route, no secrets, no production wiring. */
final class KiComEngramMcpContract
{
    private PDO $db;
    private string $owner;
    public function __construct(PDO $db, string $owner)
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9._:-]{2,63}\z/D', $owner)) throw new InvalidArgumentException('owner');
        $this->db=$db; $this->owner=$owner;
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE IF NOT EXISTS mcp_request_nonces(nonce TEXT PRIMARY KEY, owner TEXT NOT NULL, used_at INTEGER NOT NULL)");
    }
    /** Identity must come from an already verified server-side connector, never request JSON. */
    public function accept(array $request, array $identity, string $apiState, int $now): array
    {
        if ($apiState !== 'active') throw new RuntimeException('PRIVATE_API_INACTIVE');
        $this->exact($identity,['authenticated','owner','connector_id']);
        if ($identity['authenticated'] !== true || $identity['owner'] !== $this->owner) throw new RuntimeException('IDENTITY_DENIED');
        if (!is_string($identity['connector_id']) || !preg_match('/\A[a-z0-9][a-z0-9._:-]{2,63}\z/D',$identity['connector_id'])) throw new RuntimeException('CONNECTOR_DENIED');
        $this->exact($request,['v','op','owner','namespace','nonce','issued_at','limit','query']);
        if ($request['v'] !== 1 || $request['owner'] !== $this->owner) throw new RuntimeException('REQUEST_SCOPE_DENIED');
        if (!in_array($request['op'],['read','append'],true)) throw new RuntimeException('OP_DENIED');
        foreach (['namespace','nonce'] as $k) if (!is_string($request[$k]) || !preg_match('/\A[a-z0-9][a-z0-9._:-]{2,63}\z/D',$request[$k])) throw new RuntimeException('REQUEST_FORMAT');
        if (!is_int($request['issued_at']) || abs($now-$request['issued_at'])>60) throw new RuntimeException('REQUEST_STALE');
        if (!is_int($request['limit']) || $request['limit']<1 || $request['limit']>10) throw new RuntimeException('LIMIT_DENIED');
        if (!is_string($request['query']) || strlen($request['query'])>256) throw new RuntimeException('QUERY_DENIED');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('INSERT INTO mcp_request_nonces(nonce,owner,used_at) VALUES(?,?,?)');
            $q->execute([$request['nonce'],$this->owner,$now]);
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            if ($e instanceof PDOException && str_contains($e->getMessage(),'UNIQUE')) throw new RuntimeException('REPLAY_DENIED');
            throw $e;
        }
        return ['status'=>'ACCEPTED_SYNTHETIC_TRANSPORT_ONLY','op'=>$request['op'],'namespace'=>$request['namespace'],'limit'=>$request['limit']];
    }
    /** Explicit minimum-disclosure response projection; provenance is opaque metadata only. */
    public function projectRead(array $rows, int $limit): array
    {
        $out=[];
        foreach (array_slice($rows,0,$limit) as $r) {
            $this->exact($r,['id','revision','body','source_kind']);
            if (!is_string($r['id']) || !is_int($r['revision']) || !is_string($r['body']) || !is_string($r['source_kind'])) throw new RuntimeException('ROW_FORMAT');
            $out[]=['id'=>$r['id'],'revision'=>$r['revision'],'body'=>$r['body'],'source_kind'=>$r['source_kind']];
        }
        return ['items'=>$out,'count'=>count($out)];
    }
    private function exact(array $value,array $keys): void
    {
        $a=array_keys($value); sort($a); $b=$keys; sort($b); if ($a!==$b) throw new RuntimeException('UNKNOWN_OR_MISSING_FIELDS');
    }
}
