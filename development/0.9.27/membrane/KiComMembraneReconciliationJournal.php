<?php
declare(strict_types=1);

/**
 * DEV ONLY. Append-only staging observations about an uncertain Slack effect.
 *
 * This is intentionally NOT a reconciliation executor: caller-supplied hashes
 * and labels cannot authenticate a provider receipt, a KiCom-runtime ACK or
 * absence of a remote effect. It never sends, retries, marks an event done,
 * deletes original receipts, grants permissions, or claims exactly-once remote
 * delivery. Contradictory observations remain visible instead of overwriting
 * prior evidence. A real protected adapter must verify independent evidence
 * separately and retain existing human/action authorization boundaries.
 */
final class KiComMembraneReconciliationJournal
{
    private PDO $db;

    public function __construct(string $path)
    {
        if (!preg_match('~^/tmp/kicom-membrane-receipts-[a-z0-9_-]{6,80}/receipts\.sqlite$~D', $path)
            || is_link($path) || is_link(dirname($path)) || !is_dir(dirname($path))
            || !is_file($path)) {
            throw new InvalidArgumentException('Existing isolated receipt database required');
        }
        $this->db = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 1
        ]);
        $this->db->exec('PRAGMA busy_timeout=500');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS reconciliation_observations (
            event_hash TEXT NOT NULL,
            observation_hash TEXT NOT NULL,
            kind TEXT NOT NULL CHECK(kind IN (
                "REMOTE_EFFECT_REPORTED",
                "REMOTE_EFFECT_ABSENCE_REPORTED",
                "KICOM_ACK_REPORTED")),
            observed_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (event_hash, observation_hash)
        ) WITHOUT ROWID');
    }

    private static function output(string $code): array
    {
        return [
            'code'=>$code,
            'inspection_only'=>true,
            'independent_proof_authenticated_here'=>false,
            'effect_verified'=>false,
            'action_authorized'=>false,
            'delivery_authorized'=>false,
            'automatic_replay_allowed'=>false,
            'runtime_ack_verified'=>false
        ];
    }

    /**
     * @param string $observationHash Hash of a separate evidence artifact.
     * WARNING: Its claimed origin or content is NOT authenticated here.
     */
    public function record(
        string $eventHash,
        string $rawHash,
        string $observationHash,
        string $kind
    ): array {
        foreach ([$eventHash, $rawHash, $observationHash] as $hash) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                return self::output('EVIDENCE_IDENTITY_INVALID');
            }
        }
        if (!in_array($kind, [
            'REMOTE_EFFECT_REPORTED',
            'REMOTE_EFFECT_ABSENCE_REPORTED',
            'KICOM_ACK_REPORTED'
        ], true)) {
            return self::output('EVIDENCE_KIND_INVALID');
        }
        $locked = false;
        try {
            $this->db->exec('BEGIN IMMEDIATE');
            $locked = true;
            $stmt=$this->db->prepare('SELECT raw_hash FROM receipts WHERE event_hash=?');
            $stmt->execute([$eventHash]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !hash_equals($row['raw_hash'], $rawHash)) {
                $this->db->exec('ROLLBACK');$locked=false;
                return self::output('RECEIPT_MISSING_OR_DIVERGENT');
            }
            $existing=$this->db->prepare('SELECT kind FROM reconciliation_observations
                WHERE event_hash=? AND observation_hash=?');
            $existing->execute([$eventHash,$observationHash]);
            $prior=$existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($prior) && $prior['kind'] !== $kind) {
                $this->db->exec('ROLLBACK');$locked=false;
                return self::output('EVIDENCE_ID_REUSED_FOR_DIFFERENT_CLAIM');
            }
            if (!is_array($prior)) {
                $add=$this->db->prepare('INSERT INTO reconciliation_observations
                    (event_hash,observation_hash,kind) VALUES (?,?,?)');
                $add->execute([$eventHash,$observationHash,$kind]);
            }
            $this->db->exec('COMMIT');$locked=false;
            return self::output(is_array($prior)
                ? 'EVIDENCE_ALREADY_RECORDED_UNVERIFIED'
                : 'EVIDENCE_RECORDED_UNVERIFIED');
        } catch (Throwable $e) {
            if ($locked) {
                try { $this->db->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            }
            return self::output('EVIDENCE_JOURNAL_UNAVAILABLE');
        }
    }

    public function inspect(string $eventHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $eventHash)) {
            return self::output('EVIDENCE_IDENTITY_INVALID');
        }
        try {
            $receipt=$this->db->prepare('SELECT state FROM receipts WHERE event_hash=?');
            $receipt->execute([$eventHash]);
            $row=$receipt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return self::output('RECEIPT_NOT_FOUND');
            $count=$this->db->prepare('SELECT kind, COUNT(*) AS total
                FROM reconciliation_observations WHERE event_hash=? GROUP BY kind');
            $count->execute([$eventHash]);
            $tally=[];
            foreach ($count->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $tally[$entry['kind']] = (int)$entry['total'];
            }
            if (!empty($tally['REMOTE_EFFECT_REPORTED'])
                && !empty($tally['REMOTE_EFFECT_ABSENCE_REPORTED'])) {
                return self::output('CONFLICTING_EFFECT_CLAIMS_REQUIRE_REVIEW');
            }
            if ($tally === []) return self::output('NO_EXTERNAL_EVIDENCE_RECORDED');
            return self::output('EXTERNAL_CLAIMS_REQUIRE_INDEPENDENT_REVIEW');
        } catch (Throwable $e) {
            return self::output('EVIDENCE_JOURNAL_UNAVAILABLE');
        }
    }
}
