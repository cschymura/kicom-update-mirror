<?php
declare(strict_types=1);

require_once __DIR__ . '/KiComEngramActivationReadinessGate.php';

/**
 * DEV-only activation transaction controller.
 * It has no HTTP route and cannot activate production by itself. The caller must
 * supply authenticated server-side evidence and an operator approval object.
 */
final class KiComEngramActivationTransaction
{
    public static function apply(
        PDO $db,
        array $hostEvidence,
        array $ownerEvidence,
        string $expectedOwnerBinding,
        array $operatorApproval,
        DateTimeImmutable $nowUtc,
        ?callable $afterPendingHook = null
    ): array {
        $ready = KiComEngramActivationReadinessGate::evaluate(
            $hostEvidence, $ownerEvidence, $expectedOwnerBinding, $nowUtc
        );
        self::assertApproval($operatorApproval, $ready, $nowUtc);
        self::init($db);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $nonce = $operatorApproval['approval_nonce'];
            $seen = $db->prepare('SELECT 1 FROM activation_nonces WHERE approval_nonce=?');
            $seen->execute([$nonce]);
            if ($seen->fetchColumn()) {
                throw new RuntimeException('Operator approval replay denied');
            }
            $state = $db->query('SELECT * FROM activation_state WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
            if (!$state || $state['state'] !== 'inactive') {
                throw new RuntimeException('Activation state must be inactive');
            }
            $insert = $db->prepare('INSERT INTO activation_nonces(approval_nonce,owner_binding,host_evidence_id,used_at_utc) VALUES(?,?,?,?)');
            $insert->execute([$nonce,$ready['owner_binding'],$ready['host_evidence_id'],$nowUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')]);
            $pending = $db->prepare("UPDATE activation_state SET state='pending',owner_binding=?,host_evidence_id=?,approval_nonce=? WHERE singleton=1 AND state='inactive'");
            $pending->execute([$ready['owner_binding'],$ready['host_evidence_id'],$nonce]);
            if ($pending->rowCount() !== 1) {
                throw new RuntimeException('Atomic inactive-to-pending transition failed');
            }
            if ($afterPendingHook !== null) {
                $afterPendingHook();
            }
            $activate = $db->prepare("UPDATE activation_state SET state='active' WHERE singleton=1 AND state='pending' AND approval_nonce=?");
            $activate->execute([$nonce]);
            if ($activate->rowCount() !== 1) {
                throw new RuntimeException('Atomic pending-to-active transition failed');
            }
            $db->exec('COMMIT');
            return ['activated'=>true,'status'=>'SYNTHETIC_ACTIVATION_TRANSACTION_COMMITTED','owner_binding'=>$ready['owner_binding'],'host_evidence_id'=>$ready['host_evidence_id']];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->exec('ROLLBACK');
            throw $e;
        }
    }

    public static function state(PDO $db): string
    {
        self::init($db);
        return (string)$db->query('SELECT state FROM activation_state WHERE singleton=1')->fetchColumn();
    }

    private static function init(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('DEV transaction requires SQLite');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE IF NOT EXISTS activation_state(singleton INTEGER PRIMARY KEY CHECK(singleton=1),state TEXT NOT NULL CHECK(state IN (\'inactive\',\'pending\',\'active\')),owner_binding TEXT NOT NULL DEFAULT \'\',host_evidence_id TEXT NOT NULL DEFAULT \'\',approval_nonce TEXT NOT NULL DEFAULT \'\')');
        $db->exec("INSERT OR IGNORE INTO activation_state(singleton,state) VALUES(1,'inactive')");
        $db->exec('CREATE TABLE IF NOT EXISTS activation_nonces(approval_nonce TEXT PRIMARY KEY,owner_binding TEXT NOT NULL,host_evidence_id TEXT NOT NULL,used_at_utc TEXT NOT NULL)');
    }

    private static function assertApproval(array $approval, array $ready, DateTimeImmutable $nowUtc): void
    {
        $expected = ['schema','approved','purpose','owner_binding','host_evidence_id','approval_nonce','approved_at_utc'];
        $actual = array_keys($approval); sort($actual,SORT_STRING); sort($expected,SORT_STRING);
        if ($actual !== $expected) throw new RuntimeException('Unknown or missing operator approval field');
        if ($approval['schema'] !== 'mirage-activation-approval/v1' || $approval['approved'] !== true || $approval['purpose'] !== 'activate-private-engram') {
            throw new RuntimeException('Explicit operator activation approval missing');
        }
        if (!is_string($approval['owner_binding']) || !hash_equals($ready['owner_binding'],$approval['owner_binding']) || !is_string($approval['host_evidence_id']) || !hash_equals($ready['host_evidence_id'],$approval['host_evidence_id'])) {
            throw new RuntimeException('Operator approval scope mismatch');
        }
        if (!is_string($approval['approval_nonce']) || !preg_match('/\A[a-f0-9]{64}\z/D',$approval['approval_nonce'])) throw new RuntimeException('Invalid approval nonce');
        $v=$approval['approved_at_utc'];
        if (!is_string($v) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$v)) throw new RuntimeException('Invalid approval timestamp');
        $t=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new DateTimeZone('UTC'));
        if (!$t || $t->format('Y-m-d\TH:i:s\Z') !== $v) throw new RuntimeException('Impossible approval timestamp');
        $age=$nowUtc->setTimezone(new DateTimeZone('UTC'))->getTimestamp()-$t->getTimestamp();
        if ($age < 0 || $age > 300) throw new RuntimeException('Stale or future operator approval');
    }
}
