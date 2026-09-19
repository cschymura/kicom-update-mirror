<?php
declare(strict_types=1);

/**
 * KiCom PAM v0.1 — append-only observations and bounded work checkpoints.
 *
 * Development module only: this class never executes commands, grants authority,
 * changes genomes, opens sessions, reads arbitrary URLs or installs releases.
 * A trusted caller must recheck actual action authorization at execution time.
 */
final class KiComPam
{
    private const STATES = ['UNKNOWN', 'AVAILABLE', 'UNAVAILABLE', 'FORBIDDEN', 'DEGRADED', 'STALE'];
    private const RESULTS = ['SUCCEEDED', 'FAILED', 'BLOCKED'];

    /** @var callable():int */
    private $clock;

    public function __construct(private PDO $db, ?callable $clock = null)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new InvalidArgumentException('PAM requires the existing SQLite PDO connection');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA busy_timeout = 5000');
        $this->clock = $clock ?? static fn(): int => time();
        $this->migrate();
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private static function sha(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new InvalidArgumentException('Expected lowercase SHA-256');
        }
        return $value;
    }

    private static function short(string $value, int $limit): string
    {
        if ($value === '' || strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
            throw new InvalidArgumentException('Invalid bounded text');
        }
        return $value;
    }

    /** Create only new PAM tables; never alter the existing KiCom tables. */
    private function migrate(): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->db->exec('CREATE TABLE IF NOT EXISTS pam_schema (version INTEGER NOT NULL)');
            $rows = $this->db->query('SELECT version FROM pam_schema')->fetchAll(PDO::FETCH_COLUMN);
            if (count($rows) > 1 || (count($rows) === 1 && (int) $rows[0] !== 1)) {
                throw new RuntimeException('Unknown PAM schema version');
            }
            $this->db->exec('CREATE TABLE IF NOT EXISTS pam_observations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subject TEXT NOT NULL, capability TEXT NOT NULL, state TEXT NOT NULL,
                source TEXT NOT NULL, evidence_sha256 TEXT NOT NULL,
                observed_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
                CHECK(state IN ("UNKNOWN","AVAILABLE","UNAVAILABLE","FORBIDDEN","DEGRADED","STALE"))
            )');
            $this->db->exec('CREATE INDEX IF NOT EXISTS pam_observations_latest
                ON pam_observations(subject, capability, observed_at DESC, id DESC)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS pam_actions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                idempotency_key TEXT NOT NULL UNIQUE, title TEXT NOT NULL,
                context_sha256 TEXT NOT NULL, boundary TEXT NOT NULL,
                state TEXT NOT NULL DEFAULT "READY", created_at INTEGER NOT NULL,
                claimed_at INTEGER, lease_until INTEGER, lease_sha256 TEXT,
                CHECK(boundary IN ("internal","protected-external")),
                CHECK(state IN ("READY","LEASED","SUCCEEDED","FAILED","BLOCKED"))
            )');
            $this->db->exec('CREATE INDEX IF NOT EXISTS pam_actions_queue
                ON pam_actions(state, created_at, id)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS pam_action_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, action_id INTEGER NOT NULL,
                state TEXT NOT NULL, occurred_at INTEGER NOT NULL,
                evidence_sha256 TEXT NOT NULL,
                FOREIGN KEY(action_id) REFERENCES pam_actions(id)
            )');
            $this->db->exec('CREATE TABLE IF NOT EXISTS pam_checkpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT, run_key TEXT NOT NULL UNIQUE,
                state_sha256 TEXT NOT NULL, summary TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )');
            if ($rows === []) {
                $this->db->exec('INSERT INTO pam_schema(version) VALUES(1)');
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Persist a sourced observation. This never grants or revokes permissions.
     * Store a fingerprint rather than credentials or raw response bodies.
     */
    public function observe(
        string $subject,
        string $capability,
        string $state,
        string $source,
        string $evidenceSha,
        int $ttlSeconds
    ): int {
        self::short($subject, 160);
        self::short($capability, 160);
        self::short($source, 160);
        self::sha($evidenceSha);
        if (!in_array($state, self::STATES, true) || $ttlSeconds < 0 || $ttlSeconds > 86400) {
            throw new InvalidArgumentException('Invalid state or TTL');
        }
        $now = $this->now();
        $q = $this->db->prepare('INSERT INTO pam_observations
            (subject,capability,state,source,evidence_sha256,observed_at,expires_at)
            VALUES (?,?,?,?,?,?,?)');
        $q->execute([$subject, $capability, $state, $source, $evidenceSha, $now, $now + $ttlSeconds]);
        return (int) $this->db->lastInsertId();
    }

    /** A cached observation is evidence, never an authorization decision. */
    public function perceive(string $subject, string $capability): array
    {
        self::short($subject, 160);
        self::short($capability, 160);
        $q = $this->db->prepare('SELECT * FROM pam_observations
            WHERE subject=? AND capability=? ORDER BY observed_at DESC,id DESC LIMIT 1');
        $q->execute([$subject, $capability]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['state' => 'UNKNOWN', 'source' => null, 'evidence_sha256' => null];
        }
        $row['recorded_state'] = $row['state'];
        if ($this->now() >= (int) $row['expires_at']) {
            $row['state'] = 'STALE';
        }
        return $row;
    }

    /** Idempotent queue insertion; external actions remain BLOCKED until a separately authorized executor acts. */
    public function queue(
        string $key,
        string $title,
        string $contextSha,
        string $boundary
    ): int {
        self::short($key, 128);
        self::short($title, 200);
        self::sha($contextSha);
        if (!in_array($boundary, ['internal', 'protected-external'], true)) {
            throw new InvalidArgumentException('Unknown action boundary');
        }
        $state = $boundary === 'internal' ? 'READY' : 'BLOCKED';
        $q = $this->db->prepare('INSERT OR IGNORE INTO pam_actions
            (idempotency_key,title,context_sha256,boundary,state,created_at)
            VALUES (?,?,?,?,?,?)');
        $q->execute([$key, $title, $contextSha, $boundary, $state, $this->now()]);
        $q = $this->db->prepare('SELECT id,title,context_sha256,boundary FROM pam_actions WHERE idempotency_key=?');
        $q->execute([$key]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['title'] !== $title || $row['context_sha256'] !== $contextSha || $row['boundary'] !== $boundary) {
            throw new RuntimeException('Idempotency key collision');
        }
        return (int) $row['id'];
    }

    /**
     * Claim INTERNAL work only. A lease prevents concurrent scheduled runs
     * from executing the same step. Claims are not permission grants.
     * The executor must independently enforce the current action boundary.
     */
    public function claimInternal(int $id, string $leaseSha, int $leaseSeconds = 300): bool
    {
        self::sha($leaseSha);
        if ($id < 1 || $leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Invalid claim');
        }
        $now = $this->now();
        $q = $this->db->prepare('UPDATE pam_actions SET state="LEASED",
            claimed_at=?,lease_until=?,lease_sha256=?
            WHERE id=? AND boundary="internal" AND
            (state="READY" OR (state="LEASED" AND lease_until<?))');
        $q->execute([$now, $now + $leaseSeconds, $leaseSha, $id, $now]);
        return $q->rowCount() === 1;
    }

    /** Record the result only while the caller owns a live lease. */
    public function finish(int $id, string $leaseSha, string $result, string $evidenceSha): bool
    {
        self::sha($leaseSha);
        self::sha($evidenceSha);
        if (!in_array($result, self::RESULTS, true)) {
            throw new InvalidArgumentException('Invalid action result');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $now = $this->now();
            $q = $this->db->prepare('UPDATE pam_actions SET state=?,lease_until=NULL,lease_sha256=NULL
                WHERE id=? AND state="LEASED" AND lease_sha256=? AND lease_until>=?');
            $q->execute([$result, $id, $leaseSha, $now]);
            if ($q->rowCount() !== 1) {
                $this->db->exec('ROLLBACK');
                return false;
            }
            $q = $this->db->prepare('INSERT INTO pam_action_events
                (action_id,state,occurred_at,evidence_sha256) VALUES(?,?,?,?)');
            $q->execute([$id, $result, $now, $evidenceSha]);
            $this->db->exec('COMMIT');
            return true;
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Immutable run checkpoint. Reusing a run key with different content fails closed. */
    public function checkpoint(string $runKey, string $stateSha, string $summary): int
    {
        self::short($runKey, 128);
        self::sha($stateSha);
        self::short($summary, 2000);
        $q = $this->db->prepare('INSERT OR IGNORE INTO pam_checkpoints
            (run_key,state_sha256,summary,created_at) VALUES(?,?,?,?)');
        $q->execute([$runKey, $stateSha, $summary, $this->now()]);
        $q = $this->db->prepare('SELECT id,state_sha256,summary FROM pam_checkpoints WHERE run_key=?');
        $q->execute([$runKey]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['state_sha256'] !== $stateSha || $row['summary'] !== $summary) {
            throw new RuntimeException('Checkpoint key collision');
        }
        return (int) $row['id'];
    }

    /** Return the latest durable checkpoint without treating it as a source of authority. */
    public function latestCheckpoint(): ?array
    {
        $row = $this->db->query('SELECT * FROM pam_checkpoints ORDER BY id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
