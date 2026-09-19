<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPamSnapshotSequencer.php';

/**
 * DEVELOPMENT ONLY: compare an already verified, independent high-water claim
 * against the complete local sequence + exact snapshot bytes. This class has
 * NO trusted-anchor reader, signer, network, recovery or approval API.
 *
 * The caller must independently authenticate the supplied claim from outside
 * the snapshot root/SQLite/recovery path, and retain it through rollback. An
 * ordinary PHP array or another local JSON file is NOT such an anchor.
 */
final class KiComPamHighWaterVerifier
{
    private static function refuse(string $code): array
    {
        return ['ok' => false, 'code' => $code, 'inspection_only' => true,
            'restore_permitted' => false, 'automatic_recovery_permitted' => false];
    }

    /**
     * @param array<string,mixed>|null $verifiedIndependentClaim
     * @return array<string,mixed>
     */
    public static function inspect(
        KiComPamSnapshotSequencer $sequencer,
        string $trustedSnapshotDir,
        ?array $verifiedIndependentClaim
    ): array {
        if ($verifiedIndependentClaim === null) {
            return self::refuse('INDEPENDENT_HIGH_WATER_ANCHOR_MISSING');
        }
        $expectedKeys = ['sequence','snapshot_id','snapshot_sha256','entry_sha256'];
        $keys = array_keys($verifiedIndependentClaim);
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || !is_int($verifiedIndependentClaim['sequence'])
            || $verifiedIndependentClaim['sequence'] < 1
            || $verifiedIndependentClaim['sequence'] > 10000
            || !is_string($verifiedIndependentClaim['snapshot_id'])
            || !preg_match('/^[0-9]{14}-[a-f0-9]{10}$/D', $verifiedIndependentClaim['snapshot_id'])) {
            return self::refuse('HIGH_WATER_ANCHOR_FORMAT_INVALID');
        }
        foreach (['snapshot_sha256','entry_sha256'] as $key) {
            if (!is_string($verifiedIndependentClaim[$key])
                || !preg_match('/^[a-f0-9]{64}$/D', $verifiedIndependentClaim[$key])) {
                return self::refuse('HIGH_WATER_ANCHOR_FORMAT_INVALID');
            }
        }
        $root = realpath($trustedSnapshotDir);
        if ($root === false || !is_dir($root) || is_link($trustedSnapshotDir)) {
            return self::refuse('SNAPSHOT_ROOT_UNAVAILABLE');
        }
        // Never normalize another caller-supplied path into an independent
        // trust source: this root only identifies the locally checked bytes.
        $native = $sequencer->inspect();
        if (empty($native['ok'])) {
            return self::refuse('LOCAL_SEQUENCE_' . (string) ($native['code'] ?? 'INVALID'));
        }
        $seq = $verifiedIndependentClaim['sequence'];
        if (($native['sequence'] ?? null) !== $seq
            || ($native['snapshot_id'] ?? null) !== $verifiedIndependentClaim['snapshot_id']
            || ($native['snapshot_sha256'] ?? null) !== $verifiedIndependentClaim['snapshot_sha256']) {
            return self::refuse('HIGH_WATER_ROLLBACK_OR_DIVERGENCE');
        }
        $entry = $root . '/pam-sequence/entry-' . sprintf('%010d', $seq) . '.json';
        if (is_link($entry) || !is_file($entry)
            || !hash_equals($verifiedIndependentClaim['entry_sha256'],
                (string) hash_file('sha256', $entry))) {
            return self::refuse('HIGH_WATER_ENTRY_HASH_MISMATCH');
        }
        // A manifest-only count is inadequate: an unregistered .sqlite backup
        // with no metadata must also veto "complete" inventory assertions.
        $manifests = glob($root . '/snapshot-*.json');
        $backups = glob($root . '/snapshot-*.sqlite');
        if (!is_array($manifests) || !is_array($backups)
            || count($manifests) !== $seq || count($backups) !== $seq) {
            return self::refuse('SNAPSHOT_INVENTORY_INCOMPLETE');
        }
        return [
            'ok' => true,
            'code' => 'LOCAL_SEQUENCE_MATCHES_SUPPLIED_INDEPENDENT_CLAIM',
            'sequence' => $seq,
            'snapshot_id' => $native['snapshot_id'],
            'snapshot_sha256' => $native['snapshot_sha256'],
            'entry_sha256' => $verifiedIndependentClaim['entry_sha256'],
            // Passing an array does not itself establish authentication or
            // permission. No direct link to the native recovery executor.
            'independent_anchor_authenticated_here' => false,
            'inspection_only' => true,
            'restore_permitted' => false,
            'automatic_recovery_permitted' => false
        ];
    }
}
