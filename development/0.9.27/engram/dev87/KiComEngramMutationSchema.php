<?php
declare(strict_types=1);

/**
 * DEV-87: Explicit, first-party operator-initiated private schema provisioning.
 * This class never authenticates a caller; its use MUST be gated by the original
 * KiCom admin session, CSRF, private path, backup and host checks. Never call
 * it from an MCP/token/search/write HTTP request. No existing-table migration.
 */
final class KiComEngramMutationSchema
{
    private const RECEIPTS = 'CREATE TABLE engram_mutation_receipts(
        subject TEXT NOT NULL, namespace TEXT NOT NULL,
        idempotency_key TEXT NOT NULL, operation TEXT NOT NULL,
        request_hash TEXT NOT NULL, result_json TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        grant_nonce TEXT NOT NULL,
        PRIMARY KEY(subject,namespace,idempotency_key),
        UNIQUE(subject,namespace,grant_nonce))';
    private const AUDIT = 'CREATE TABLE engram_mutation_audit(
        seq INTEGER PRIMARY KEY AUTOINCREMENT,
        subject TEXT NOT NULL, namespace TEXT NOT NULL,
        operation TEXT NOT NULL, engram_id TEXT NOT NULL,
        revision INTEGER NOT NULL, outcome TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)';
    private const REQUIRED_RECEIPTS = [
        'subject','namespace','idempotency_key','operation','request_hash',
        'result_json','created_at','grant_nonce'
    ];
    private const REQUIRED_AUDIT = [
        'seq','subject','namespace','operation','engram_id','revision',
        'outcome','created_at'
    ];
    private static function sqlite(PDO $db):void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')
            throw new RuntimeException('SQLITE_REQUIRED');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }
    private static function has(PDO $db,string $table):bool
    {
        $q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $q->execute([$table]);
        return $q->fetchColumn()!==false;
    }
    private static function columns(PDO $db,string $table,array $required):void
    {
        $cols=$db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_COLUMN,1);
        if (array_diff($required,$cols)!==[] || count($cols)!==count($required))
            throw new RuntimeException('INCOMPATIBLE_PRIVATE_MUTATION_SCHEMA');
    }
    private static function nonceUnique(PDO $db):void
    {
        $indexes=$db->query('PRAGMA index_list(engram_mutation_receipts)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $idx) {
            if ((int)$idx['unique']!==1 || (int)$idx['partial']!==0)continue;
            $name=$idx['name'];
            if (!is_string($name)||!preg_match('/\A[a-zA-Z0-9_]+\z/D',$name))continue;
            $fields=$db->query('PRAGMA index_info('.$name.')')->fetchAll(PDO::FETCH_COLUMN,2);
            if ($fields===['subject','namespace','grant_nonce'])return;
        }
        throw new RuntimeException('MISSING_DURABLE_NONCE_UNIQUE_INDEX');
    }
    /** READ-ONLY check; safe in the mutation adapter constructor. */
    public static function assertReady(PDO $db):void
    {
        self::sqlite($db);
        self::columns($db,'engram_mutation_receipts',self::REQUIRED_RECEIPTS);
        self::columns($db,'engram_mutation_audit',self::REQUIRED_AUDIT);
        self::nonceUnique($db);
        $cols=$db->query('PRAGMA table_info(engram_revisions)')->fetchAll(PDO::FETCH_COLUMN,1);
        $required=['subject','namespace','id','revision','kind','body',
            'source_kind','source_ref','entry_state','previous_hash','revision_hash'];
        if (array_diff($required,$cols)!==[])throw new RuntimeException('INCOMPATIBLE_ENGRAM_REVISIONS');
    }
    /**
     * Only for an original first-party, authorized operator-admin setup action
     * AFTER a consistent encrypted/private backup. Existing incompatible
     * schemas are REFUSED; they need a separately audited migration plan.
     */
    public static function prepareNew(PDO $db):void
    {
        self::sqlite($db);
        self::sqlite($db);
        $r=self::has($db,'engram_mutation_receipts');
        $a=self::has($db,'engram_mutation_audit');
        if ($r!==$a)throw new RuntimeException('PARTIAL_PRIVATE_MUTATION_SCHEMA');
        if ($r) { self::assertReady($db);return; }
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->exec(self::RECEIPTS);
            $db->exec(self::AUDIT);
            self::assertReady($db);
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            if ($db->inTransaction())$db->exec('ROLLBACK');
            throw $e;
        }
    }
}
