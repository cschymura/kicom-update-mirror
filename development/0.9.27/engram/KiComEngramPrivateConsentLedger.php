<?php
declare(strict_types=1);

/**
 * Isolated DEV-only exact-record one-time consent ledger. Not an endpoint and
 * NOT a human-approval authenticator.
 *
 * Trusted KiCom integration MUST issue() only after independently verifying a
 * fresh human review of this exact memory record, from a separate protected
 * session/action; the independentReview callback MUST NOT read client-provided
 * "approved" flags, Slack, mail or model instructions as authorization.
 * consume() is the callback supplied to KiComEngramIngestionGate.
 *
 * Stores digests and random receipt IDs, NEVER raw memory or private keys.
 * Expects preprovisioned 0700 non-web directory. No public receipt issuance.
 */
final class KiComEngramPrivateConsentLedger
{
    private PDO $db;
    private string $path;
    private string $dir;
    private $independentReview;

    private const FIELDS = [
        'subject', 'namespace', 'kind', 'body_sha256',
        'source_kind', 'source_ref_sha256', 'sensitivity',
    ];

    public function __construct(string $privateDirectory, string $publicDocumentRoot, callable $independentReview)
    {
        if (is_link($privateDirectory) || !is_dir($privateDirectory)) {
            throw new RuntimeException('ENGRAM_CONSENT_DIR_INVALID');
        }
        $dir = realpath($privateDirectory);
        $web = realpath($publicDocumentRoot);
        if ($dir === false || $web === false || !is_dir($web)
            || $dir === $web || str_starts_with($dir, $web . DIRECTORY_SEPARATOR)
            || (fileperms($dir) & 0077) !== 0) {
            throw new RuntimeException('ENGRAM_CONSENT_DIR_INVALID');
        }
        $this->dir = $dir;
        $this->path = $dir . '/engram-consent.sqlite';
        $this->independentReview = $independentReview;
        if (is_link($this->path)) throw new RuntimeException('ENGRAM_CONSENT_DB_INVALID');
        if (!file_exists($this->path)) {
            $old = umask(0077);
            try {
                $h = @fopen($this->path, 'x+b');
                if ($h === false) throw new RuntimeException('ENGRAM_CONSENT_DB_INVALID');
                fclose($h);
                chmod($this->path, 0600);
            } finally { umask($old); }
        }
        $this->assertPrivateFiles();
        $old = umask(0077);
        try {
            $this->db = new PDO('sqlite:' . $this->path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->db->exec('PRAGMA busy_timeout = 5000');
            $this->db->exec('PRAGMA journal_mode = WAL');
            $this->db->exec('PRAGMA synchronous = FULL');
            $this->db->exec('CREATE TABLE IF NOT EXISTS approvals (
                receipt_id TEXT PRIMARY KEY,
                binding_sha256 TEXT NOT NULL,
                issued_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                consumed_at INTEGER,
                CHECK(expires_at > issued_at)
            )');
            // At most ONE outstanding approval per exact memory digest.
            $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS consent_unique_pending
                ON approvals(binding_sha256) WHERE consumed_at IS NULL');
            if ($this->db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('ENGRAM_CONSENT_DB_INVALID');
            }
            $this->assertPrivateFiles();
        } finally { umask($old); }
    }

    private function assertPrivateFiles(): void
    {
        clearstatcache(true, $this->dir);
        if (is_link($this->dir) || !is_dir($this->dir)
            || (fileperms($this->dir) & 0077) !== 0) {
            throw new RuntimeException('ENGRAM_CONSENT_DIR_INVALID');
        }
        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm'] as $path) {
            clearstatcache(true, $path);
            if (is_link($path)) throw new RuntimeException('ENGRAM_CONSENT_DB_INVALID');
            if (!file_exists($path)) continue;
            $st = @stat($path);
            if ($st === false || !is_file($path) || $st['nlink'] !== 1
                || ($st['mode'] & 0077) !== 0) {
                throw new RuntimeException('ENGRAM_CONSENT_DB_INVALID');
            }
        }
    }

    private static function digest(array $binding): string
    {
        $keys = array_keys($binding);
        sort($keys);
        $expected = self::FIELDS;
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('ENGRAM_CONSENT_BINDING_INVALID');
        }
        foreach (self::FIELDS as $key) {
            if (!is_string($binding[$key]) || $binding[$key] === ''
                || strlen($binding[$key]) > 256) {
                throw new RuntimeException('ENGRAM_CONSENT_BINDING_INVALID');
            }
        }
        foreach (['subject','namespace','kind','source_kind','sensitivity'] as $key) {
            if (!preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $binding[$key])) {
                throw new RuntimeException('ENGRAM_CONSENT_BINDING_INVALID');
            }
        }
        foreach (['body_sha256','source_ref_sha256'] as $key) {
            if (!preg_match('/\A[a-f0-9]{64}\z/D', $binding[$key])) {
                throw new RuntimeException('ENGRAM_CONSENT_BINDING_INVALID');
            }
        }
        $ordered = [];
        foreach (self::FIELDS as $key) $ordered[$key] = $binding[$key];
        return hash('sha256', json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Only a trusted SERVER-SIDE human-reviewed flow may call this method.
     * Reviewer must independently bind the exact record and subject.
     * Receipt metadata never contains the record contents.
     */
    public function issue(array $binding, int $ttlSeconds = 300): array
    {
        $digest = self::digest($binding);
        if ($ttlSeconds < 30 || $ttlSeconds > 600) {
            throw new RuntimeException('ENGRAM_CONSENT_TTL_INVALID');
        }
        // Important: callback has no access to a caller-claimed approval flag.
        if (($this->independentReview)($binding) !== true) {
            throw new RuntimeException('ENGRAM_CONSENT_REVIEW_REQUIRED');
        }
        $this->assertPrivateFiles();
        $now = time();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            // An expired approval is never transferable/revivable.
            $q = $this->db->prepare('UPDATE approvals SET consumed_at=:now
                WHERE consumed_at IS NULL AND expires_at<=:now');
            $q->execute([':now'=>$now]);
            $receipt = bin2hex(random_bytes(16));
            $insert = $this->db->prepare('INSERT INTO approvals
                (receipt_id,binding_sha256,issued_at,expires_at)
                VALUES(:id,:digest,:issued,:expires)');
            $insert->execute([
                ':id'=>$receipt, ':digest'=>$digest,
                ':issued'=>$now, ':expires'=>$now+$ttlSeconds,
            ]);
            $this->db->exec('COMMIT');
            return ['receipt_id'=>$receipt, 'expires_at'=>$now+$ttlSeconds];
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Atomic and exact-binding one-time consumption; no receipt from client. */
    public function consume(array $binding): bool
    {
        $digest = self::digest($binding);
        $this->assertPrivateFiles();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $now = time();
            $q = $this->db->prepare('SELECT receipt_id FROM approvals
                WHERE binding_sha256=:digest AND consumed_at IS NULL
                AND expires_at>:now LIMIT 1');
            $q->execute([':digest'=>$digest, ':now'=>$now]);
            $id = $q->fetchColumn();
            if (!is_string($id)) {
                $this->db->exec('COMMIT');
                return false;
            }
            $consume = $this->db->prepare('UPDATE approvals SET consumed_at=:now
                WHERE receipt_id=:id AND consumed_at IS NULL AND expires_at>:now');
            $consume->execute([':now'=>$now, ':id'=>$id]);
            $ok = $consume->rowCount() === 1;
            $this->db->exec('COMMIT');
            return $ok;
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
}
