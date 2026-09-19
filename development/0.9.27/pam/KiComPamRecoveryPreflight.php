<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPamRecoveryGate.php';
require_once __DIR__ . '/KiComPamSnapshotSequencer.php';

/**
 * KiCom 0.9.27 development-only pre-quarantine recovery decision.
 *
 * Read-only; no DB replacement, repair, reconfiguration or permission grant.
 * The preflight is not a substitute for KiCom's own independent protected
 * recovery authorization, verified backups, LKG/Genome or rollback.
 */
final class KiComPamRecoveryPreflight
{
    public static function inspect(): array
    {
        if (!function_exists('kicomSqliteSnapshotDir')) {
            return self::blocked('TRUSTED_RECOVERY_RUNTIME_UNAVAILABLE');
        }
        $dir = kicomSqliteSnapshotDir();
        if (!is_dir($dir) || is_link($dir)) {
            return self::blocked('SNAPSHOT_DIRECTORY_UNAVAILABLE');
        }
        $ledgerDir = $dir . '/pam-sequence';
        if (is_link($ledgerDir)) {
            return self::blocked('UNTRUSTED_LEDGER_PATH');
        }
        if (is_dir($ledgerDir)) {
            try {
                $ledger = new KiComPamSnapshotSequencer($dir);
                $inspection = $ledger->inspect();
            } catch (Throwable $e) {
                return self::blocked('SEQUENCE_LEDGER_UNTRUSTED');
            }
            if (empty($inspection['ok'])) {
                return self::blocked('SEQUENCE_' . (string) ($inspection['code'] ?? 'INVALID'));
            }
            // Local journal is not independently anchored against complete
            // truncation/rollback. It supplies a candidate for a separate
            // human/trust-root review, NEVER automatic restore authority.
            return [
                'ok' => true, 'code' => 'SEQUENCED_CANDIDATE_REQUIRES_ANCHOR',
                'snapshot_id' => $inspection['snapshot_id'],
                'snapshot_sha256' => $inspection['snapshot_sha256'],
                'inspection_only' => true, 'restore_permitted' => false,
                'automatic_recovery_permitted' => false
            ];
        }
        $legacy = KiComPamRecoveryGate::inspect();
        if (empty($legacy['ok'])) {
            return self::blocked('LEGACY_' . (string)($legacy['code'] ?? 'INVALID'));
        }
        // A unique legacy second and valid bytes do not authorize automatic
        // recovery; non-sequenced history has no monotonic/trusted generation.
        return [
            'ok' => true, 'code' => 'LEGACY_CANDIDATE_REQUIRES_REVIEW',
            'snapshot_id' => $legacy['snapshot_id'],
            'snapshot_sha256' => $legacy['snapshot_sha256'],
            'inspection_only' => true, 'restore_permitted' => false,
            'automatic_recovery_permitted' => false
        ];
    }

    private static function blocked(string $reason): array
    {
        return [
            'ok' => false, 'code' => $reason,
            'inspection_only' => true,
            'restore_permitted' => false,
            'automatic_recovery_permitted' => false
        ];
    }
}
