<?php
declare(strict_types=1);

require_once __DIR__ . '/KiComEngramHostIsolationGate.php';

/**
 * DEV-only adapter from reviewed server-side probe facts to the public-safe
 * host-isolation attestation. It never returns paths, UIDs, usernames or
 * private configuration. Probe facts must be produced locally by authorized
 * host code; request parameters are not an acceptable source.
 */
final class KiComEngramHostEvidenceAdapter
{
    private const BOOLEAN_FACTS = [
        'all_vhosts_reviewed', 'all_aliases_reviewed', 'default_host_reviewed',
        'private_paths_outside_all_webroots', 'private_parent_mode_verified',
        'data_mode_verified', 'backup_mode_verified', 'php_identity_verified',
        'cross_app_read_denied', 'cross_app_write_denied',
        'open_basedir_boundary_verified', 'backup_snapshot_verified',
        'backup_restore_verified', 'rollback_boundary_verified',
        'retention_delete_verified',
    ];

    /** @return array{eligible:bool,status:string,evidence_id:string,checks:int} */
    public static function evaluateReviewedFacts(array $facts, string $hostBinding): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $hostBinding)) {
            throw new InvalidArgumentException('Host binding must be SHA-256 hex');
        }
        $allowed = array_merge(self::BOOLEAN_FACTS, [
            'schema', 'checked_at_utc', 'synthetic_only', 'private_api_inactive',
            'mcp_connector_connected'
        ]);
        foreach (array_keys($facts) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new RuntimeException('Unknown or privacy-sensitive host fact');
            }
        }
        if (($facts['schema'] ?? null) !== 'mirage-host-facts/v1') {
            throw new RuntimeException('Unsupported reviewed host-facts schema');
        }
        foreach (self::BOOLEAN_FACTS as $field) {
            if (!array_key_exists($field, $facts) || !is_bool($facts[$field])) {
                throw new RuntimeException('Missing or non-boolean reviewed host fact: ' . $field);
            }
        }
        foreach (['synthetic_only', 'private_api_inactive', 'mcp_connector_connected'] as $field) {
            if (!array_key_exists($field, $facts) || !is_bool($facts[$field])) {
                throw new RuntimeException('Missing or non-boolean lifecycle fact: ' . $field);
            }
        }
        $timestamp = $facts['checked_at_utc'] ?? null;
        if (!is_string($timestamp)) {
            throw new RuntimeException('Reviewed host facts require UTC timestamp');
        }
        $attestation = $facts;
        unset($attestation['schema']);
        $attestation['schema'] = 'mirage-host-isolation/v1';
        $attestation['host_binding'] = $hostBinding;
        return KiComEngramHostIsolationGate::evaluate($attestation, $hostBinding);
    }
}
