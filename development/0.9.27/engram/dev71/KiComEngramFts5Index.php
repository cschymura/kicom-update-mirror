<?php
declare(strict_types=1);

/**
 * DEV-only optional lexical index. Canonical data remains engram_revisions.
 * This class never enables itself and never calls an external service.
 */
final class KiComEngramFts5Index
{
    public const INDEX_VERSION = 1;
    public function __construct(private PDO $db) {}

    public function capability(): array
    {
        try {
            $this->db->exec("CREATE VIRTUAL TABLE temp.__kicom_fts5_probe USING fts5(body)");
            $this->db->exec("DROP TABLE temp.__kicom_fts5_probe");
            return ['fts5' => true, 'index_version' => self::INDEX_VERSION, 'external_service' => false];
        } catch (Throwable $e) {
            return ['fts5' => false, 'index_version' => self::INDEX_VERSION, 'external_service' => false];
        }
    }

    public function migrateDisabled(): void
    {
        if (!$this->capability()['fts5']) throw new RuntimeException('FTS5 unavailable');
        $this->db->exec('CREATE TABLE IF NOT EXISTS engram_search_index_meta (index_version INTEGER NOT NULL, enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled=0), rebuilt_at TEXT)');
        $count=(int)$this->db->query('SELECT COUNT(*) FROM engram_search_index_meta')->fetchColumn();
        if ($count===0) {
            $s=$this->db->prepare('INSERT INTO engram_search_index_meta(index_version,enabled,rebuilt_at) VALUES(?,0,NULL)');
            $s->execute([self::INDEX_VERSION]);
        }
        $this->db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS engram_search_fts USING fts5(subject UNINDEXED, namespace UNINDEXED, id UNINDEXED, revision UNINDEXED, body, tokenize='unicode61')");
    }

    public function rebuild(): int
    {
        $this->migrateDisabled();
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM engram_search_fts');
            $sql="INSERT INTO engram_search_fts(subject,namespace,id,revision,body)
                  SELECT r.subject,r.namespace,r.id,r.revision,r.body FROM engram_revisions r
                  WHERE r.entry_state='active' AND r.revision=(SELECT MAX(x.revision) FROM engram_revisions x WHERE x.subject=r.subject AND x.namespace=r.namespace AND x.id=r.id)";
            $n=$this->db->exec($sql);
            $u=$this->db->prepare('UPDATE engram_search_index_meta SET index_version=?, enabled=0, rebuilt_at=CURRENT_TIMESTAMP');
            $u->execute([self::INDEX_VERSION]);
            $this->db->commit();
            return (int)$n;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Strict owner/namespace isolation; caller identity must already be server verified. */
    public function search(string $subject,string $namespace,string $query,int $limit=10): array
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D',$subject) || !preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D',$namespace)) throw new InvalidArgumentException('invalid scope key');
        if ($query==='' || strlen($query)>128 || $limit<1 || $limit>20) throw new InvalidArgumentException('invalid query');
        $s=$this->db->prepare('SELECT id,revision,body,bm25(engram_search_fts) AS rank FROM engram_search_fts WHERE subject=:s AND namespace=:n AND engram_search_fts MATCH :q ORDER BY rank LIMIT :lim');
        $s->bindValue(':s',$subject,PDO::PARAM_STR); $s->bindValue(':n',$namespace,PDO::PARAM_STR); $s->bindValue(':q',$query,PDO::PARAM_STR); $s->bindValue(':lim',$limit,PDO::PARAM_INT); $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function status(): array
    {
        $cap=$this->capability();
        if (!$cap['fts5']) return $cap+['prepared'=>false,'enabled'=>false];
        $prepared=(bool)$this->db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='engram_search_index_meta'")->fetchColumn();
        return $cap+['prepared'=>$prepared,'enabled'=>false];
    }
}
