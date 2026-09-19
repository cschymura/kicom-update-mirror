<?php
declare(strict_types=1);

/**
 * DEV ONLY: deterministic fail-closed recovery review state machine.
 *
 * Inputs are read-only observations, NOT bearer capabilities. This class has
 * no filesystem, transport, backup, quiescence, restore or approval methods.
 * Even a full set of matching assertions cannot authorize a native recovery:
 * independent trust-root authentication and quiesced original preservation
 * must occur in a separately reviewed, protected runtime action boundary.
 */
final class KiComPamRecoveryDecision
{
    private static function state(string $code, string $stage, array $evidence = []): array
    {
        return [
            'code' => $code,
            'stage' => $stage,
            'inspection_only' => true,
            'restore_permitted' => false,
            'automatic_recovery_permitted' => false,
            'evidence' => $evidence
        ];
    }

    public static function review(
        array $candidate,
        array $originalBefore,
        array $originalAfter,
        array $highWater,
        array $preservation
    ): array {
        // No dependency on the caller's asserted approval booleans. Enforce
        // exact structure and a candidate that has actually been identified.
        if (empty($candidate['ok'])
            || !in_array($candidate['code'] ?? null,
                ['LEGACY_CANDIDATE_REQUIRES_REVIEW',
                 'SEQUENCED_CANDIDATE_REQUIRES_ANCHOR'], true)
            || !is_string($candidate['snapshot_id'] ?? null)
            || !preg_match('/^[0-9]{14}-[a-f0-9]{10}$/D', $candidate['snapshot_id'])
            || !is_string($candidate['snapshot_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $candidate['snapshot_sha256'])
            || ($candidate['restore_permitted'] ?? null) !== false
            || ($candidate['automatic_recovery_permitted'] ?? null) !== false) {
            return self::state('BLOCKED_CANDIDATE_UNVERIFIED', 'CANDIDATE');
        }
        if (empty($originalBefore['ok'])
            || empty($originalAfter['ok'])
            || !is_array($originalBefore['files'] ?? null)
            || !is_array($originalAfter['files'] ?? null)
            || array_keys($originalBefore['files']) !== ['db', 'wal', 'shm']
            || array_keys($originalAfter['files']) !== ['db', 'wal', 'shm']
            || ($originalBefore['files']['db']['present'] ?? null) !== true
            || ($originalAfter['files']['db']['present'] ?? null) !== true) {
            return self::state('BLOCKED_ORIGINAL_INVENTORY_MISSING', 'ORIGINALS');
        }
        foreach (['db','wal','shm'] as $kind) {
            $first = $originalBefore['files'][$kind];
            $last = $originalAfter['files'][$kind];
            if (!is_array($first) || !is_array($last)
                || !is_bool($first['present'] ?? null)
                || !is_bool($last['present'] ?? null)
                || ($first['present'] && (!is_int($first['bytes'] ?? null)
                    || $first['bytes'] < 0 || !is_string($first['sha256'] ?? null)
                    || !preg_match('/^[a-f0-9]{64}$/D', $first['sha256'])))
                || (!$first['present'] && array_keys($first) !== ['present'])
                || ($last['present'] && (!is_int($last['bytes'] ?? null)
                    || $last['bytes'] < 0 || !is_string($last['sha256'] ?? null)
                    || !preg_match('/^[a-f0-9]{64}$/D', $last['sha256'])))
                || (!$last['present'] && array_keys($last) !== ['present'])) {
                return self::state('BLOCKED_ORIGINAL_INVENTORY_INVALID', 'ORIGINALS');
            }
            if ($first !== $last) {
                return self::state('BLOCKED_ORIGINAL_CHANGED_' . strtoupper($kind), 'ORIGINALS');
            }
        }
        if (($candidate['code'] ?? null) !== 'SEQUENCED_CANDIDATE_REQUIRES_ANCHOR') {
            return self::state('BLOCKED_LEGACY_REQUIRES_RECONCILIATION', 'ANCHOR');
        }
        if (empty($highWater['ok'])
            || ($highWater['code'] ?? null) !== 'LOCAL_SEQUENCE_MATCHES_SUPPLIED_INDEPENDENT_CLAIM'
            || ($highWater['snapshot_id'] ?? null) !== $candidate['snapshot_id']
            || ($highWater['snapshot_sha256'] ?? null) !== $candidate['snapshot_sha256']
            || ($highWater['restore_permitted'] ?? null) !== false) {
            return self::state('BLOCKED_HIGH_WATER_MISSING_OR_DIVERGENT', 'ANCHOR');
        }
        // Even a caller-supplied "authenticated=true" would be mere data;
        // KiCom's current high-water verifier correctly states false.
        if (($highWater['independent_anchor_authenticated_here'] ?? null) !== true) {
            return self::state('BLOCKED_ANCHOR_NOT_INDEPENDENTLY_AUTHENTICATED', 'ANCHOR',
                ['candidate_sha256' => $candidate['snapshot_sha256']]);
        }
        // Preservation claims are NOT accepted as a production release. This
        // stage is useful for fault-injection tests and future audit wiring.
        if (($preservation['quiesced'] ?? null) !== true
            || ($preservation['originals_preserved'] ?? null) !== true
            || ($preservation['originals_independently_verified'] ?? null) !== true) {
            return self::state('BLOCKED_ORIGINAL_PRESERVATION_UNVERIFIED', 'PRESERVATION');
        }
        return self::state('REVIEW_REQUIRED_AT_PROTECTED_RECOVERY_BOUNDARY', 'REVIEW', [
            'candidate_id' => $candidate['snapshot_id'],
            'candidate_sha256' => $candidate['snapshot_sha256']
        ]);
    }
}
