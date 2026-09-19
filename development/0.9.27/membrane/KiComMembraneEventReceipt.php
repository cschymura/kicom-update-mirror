<?php
declare(strict_types=1);

/**
 * STAGING-ONLY durable intake reservation for *already verified* Slack event
 * metadata. No Slack API, message body, credentials, action dispatch or ACK
 * sender. This is NOT a shared production recovery authority.
 *
 * Critical rule: a recorded reservation is NEVER automatically re-run after
 * a timeout, a crash or a retry. It remains NEEDS_RECONCILIATION until an
 * independently verified external effect is inspected outside this class.
 * An SQL transaction cannot atomically commit a remote Slack delivery.
 */
final class KiComMembraneEventReceipt
{
    private PDO $db;

    public function __construct(string $path)
    {
        // Explicitly disposable private CI staging path; not a live KiCom DB.
        if (!preg_match('~^/tmp/kicom-membrane-receipts-[a-z0-9_-]{6,80}/receipts\.sqlite$~D', $path)
            || is_link($path) || is_link(dirname($path))
            || !is_dir(dirname($path))) {
            throw new InvalidArgumentException('Only isolated receipt database path permitted');
        }
        $this->db = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 1,
        ]);
        $this->db->exec('PRAGMA busy_timeout=500');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS receipts (
            event_hash TEXT PRIMARY KEY,
            raw_hash TEXT NOT NULL,
            state TEXT NOT NULL CHECK(state IN
                ("NEEDS_RECONCILIATION", "EXTERNALLY_RECONCILED")),
            inserted_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) WITHOUT ROWID');
    }

    /**
     * @return array<string,scalar>
     * The caller MUST have verified signature/workspace/channel first;
     * passing arbitrary hashes is not Slack authentication.
     */
    public function reserve(string $eventHash, string $rawHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $eventHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $rawHash)) {
            return self::state('INVALID_RECEIPT_IDENTITY');
        }
        try {
            $this->db->exec('BEGIN IMMEDIATE');
            $select=$this->db->prepare('SELECT raw_hash, state FROM receipts WHERE event_hash = ?');
            $select->execute([$eventHash]);
            $row=$select->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $this->db->commit();
                if (!hash_equals($row['raw_hash'], $rawHash)) {
                    return self::state('EVENT_ID_HASH_COLLISION');
                }
                return self::state('EXISTING_RECEIPT_REQUIRES_RECONCILIATION');
            }
            $insert=$this->db->prepare('INSERT INTO receipts
                (event_hash, raw_hash, state)
                VALUES (?, ?, "NEEDS_RECONCILIATION")');
            $insert->execute([$eventHash,$rawHash]);
            $this->db->commit();
            // Even a new reservation is NOT an external dispatch permit.
            return self::state('NEW_DURABLE_RECEIPT');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return self::state('RECEIPT_DATABASE_UNAVAILABLE');
        }
    }

    public function inspect(string $eventHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $eventHash)) {
            return self::state('INVALID_RECEIPT_IDENTITY');
        }
        try {
            $stmt=$this->db->prepare('SELECT state FROM receipts WHERE event_hash=?');
            $stmt->execute([$eventHash]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            return self::state($row ? 'RECEIPT_'.(string)$row['state'] : 'RECEIPT_NOT_FOUND');
        } catch (Throwable $e) {
            return self::state('RECEIPT_DATABASE_UNAVAILABLE');
        }
    }

    private static function state(string $code): array
    {
        return [
            'code'=>$code,
            'inspection_only'=>true,
            'action_authorized'=>false,
            'delivery_authorized'=>false,
            'automatic_replay_allowed'=>false,
            'runtime_ack_verified'=>false,
        ];
    }
}
