<?php
declare(strict_types=1);

/**
 * DEV-only, pure policy gate for Mirage Engram host activation.
 *
 * It does NOT discover hosting configuration and does NOT activate Engram.
 * It consumes only a server-side attestation produced by separately reviewed
 * probes. Paths, UIDs, credentials and private contents are intentionally not
 * accepted or returned. The caller remains responsible for authenticating the
 * operator and for binding the attestation to the current host/config digest.
 */
final class KiComEngramHostIsolationGate
{
    private const REQUIRED_TRUE = [
        'all_vhosts_reviewed',
        'all_aliases_reviewed',
        'default_host_reviewed',
        'private_paths_outside_all_webroots',
        'private_parent_mode_verified',
        'data_mode_verified',
        'backup_mode_verified',
        'php_identity_verified',
        'cross_app_read_denied',
        'cross_app_write_denied',
        'open_basedir_boundary_verified',
        'backup_snapshot_verified',
        'backup_restore_verified',
        'rollback_boundary_verified',
        'retention_delete_verified',
    ];

    /**
     * @return array{eligible:bool,status:string,evidence_id:string,checks:int}
     */
    public static function evaluate(array $attestation, string $expectedHostBinding): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $expectedHostBinding)) {
            throw new InvalidArgumentException('Expected host binding must be SHA-256 hex');
        }
        $allowed = array_merge(self::REQUIRED_TRUE, [
            'schema', 'host_binding', 'synthetic_only', 'private_api_inactive',
            'mcp_connector_connected', 'checked_at_utc'
        ]);
        foreach (array_keys($attestation) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new RuntimeException('Unknown host-isolation attestation field');
            }
        }
        if (($attestation['schema'] ?? null) !== 'mirage-host-isolation/v1') {
            throw new RuntimeException('Unsupported host-isolation attestation schema');
        }
        $binding = $attestation['host_binding'] ?? null;
        if (!is_string($binding) || !preg_match('/\A[a-f0-9]{64}\z/D', $binding)
            || !hash_equals($expectedHostBinding, $binding)) {
            throw new RuntimeException('Host/config binding mismatch');
        }
        if (($attestation['synthetic_only'] ?? null) !== true
            || ($attestation['private_api_inactive'] ?? null) !== true) {
            throw new RuntimeException('Pre-activation attestation must remain synthetic and API-inactive');
        }
        // A connected MCP identity is deliberately NOT a prerequisite for host
        // isolation. Connector activation is a later, independently authorized gate.
        if (($attestation['mcp_connector_connected'] ?? null) !== false) {
            throw new RuntimeException('Host-isolation gate must precede MCP connection');
        }
        $timestamp = $attestation['checked_at_utc'] ?? null;
        if (!is_string($timestamp) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $timestamp)) {
            throw new RuntimeException('Invalid UTC evidence timestamp');
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
            throw new RuntimeException('Impossible UTC evidence timestamp');
        }
        foreach (self::REQUIRED_TRUE as $field) {
            if (($attestation[$field] ?? null) !== true) {
                throw new RuntimeException('Host isolation incomplete: ' . $field);
            }
        }
        $canonical = $attestation;
        ksort($canonical, SORT_STRING);
        $evidenceId = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return [
            'eligible' => true,
            'status' => 'HOST_ISOLATION_EVIDENCE_COMPLETE_API_STILL_INACTIVE',
            'evidence_id' => $evidenceId,
            'checks' => count(self::REQUIRED_TRUE),
        ];
    }
}
