<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPam.php';

/**
 * Explicit KCL/1 read-only evidence adapter for the PAM development module.
 * Callers supply already-fetched responses from fixed, trusted KiCom endpoints.
 * No network access, action execution, authorization or feed publishing.
 */
final class KiComPamKclAdapter
{
    private const ENDPOINTS = ['HELLO', 'GENOME_STATUS', 'SQLITE_STATUS', 'UPDATE_STATUS'];
    private const SUCCESS = [
        'HELLO' => 'hello',
        'GENOME_STATUS' => 'living_status',
        'SQLITE_STATUS' => 'sqlite_status',
        'UPDATE_STATUS' => 'update_status',
    ];

    public function __construct(private KiComPam $pam)
    {
    }

    /** @return array<string,string> */
    private function facts(string $wire, string $endpoint): array
    {
        $wire = str_replace("\r\n", "\n", $wire);
        $wire = rtrim($wire, "\n");
        $expectedStatus = self::SUCCESS[$endpoint] ?? null;
        if ($expectedStatus === null || strlen($wire) > 32768
            || !str_starts_with($wire, "KCL/1\nOK " . $expectedStatus . "\n")
            || !str_ends_with($wire, "\nEND")) {
            throw new InvalidArgumentException('Unexpected, incomplete or unsuccessful KCL/1 response');
        }
        $found = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $wire)) as $line) {
            if (!str_starts_with($line, 'FACT ')) {
                continue;
            }
            if (!preg_match('/^FACT ([a-z][a-z0-9_]*)=(.+)$/D', $line, $m)) {
                throw new InvalidArgumentException('Malformed KCL fact');
            }
            if (array_key_exists($m[1], $found)) {
                throw new InvalidArgumentException('Duplicate KCL fact');
            }
            $raw = $m[2];
            if (str_starts_with($raw, '"')) {
                $decoded = json_decode($raw, true);
                if (!is_string($decoded)) {
                    throw new InvalidArgumentException('Malformed quoted KCL fact');
                }
                $found[$m[1]] = $decoded;
            } else {
                $found[$m[1]] = $raw;
            }
        }
        return $found;
    }

    /**
     * Read-only observation cycle; its outputs are NOT action authorization.
     * Exactly the required four endpoint responses must be provided by the caller.
     */
    public function capture(string $runKey, array $responses): array
    {
        $keys = array_keys($responses);
        sort($keys);
        $expected = self::ENDPOINTS;
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Unexpected or missing evidence endpoint');
        }
        $facts = [];
        $evidence = [];
        foreach (self::ENDPOINTS as $endpoint) {
            if (!is_string($responses[$endpoint])) {
                throw new InvalidArgumentException('KCL response must be text');
            }
            $facts[$endpoint] = $this->facts($responses[$endpoint], $endpoint);
            $evidence[$endpoint] = hash('sha256', $responses[$endpoint]);
        }

        $version = $facts['HELLO']['version'] ?? '';
        if (!preg_match('/^0\.[0-9]+\.[0-9]+$/D', $version)) {
            throw new InvalidArgumentException('Unknown KiCom runtime version');
        }
        $this->pam->observe('KiCom:' . $version, 'runtime', 'AVAILABLE', 'KCL:HELLO', $evidence['HELLO'], 300);

        $genome = $facts['GENOME_STATUS'];
        // The live 0.9.26 R3 baseline has an exact genome identity; the
        // canonical PROJECT_STATE may lag the actual trusted runtime.
        // Refuse an unknown 0.9.26 lineage even when all Boolean flags look OK.
        $lineageOk = $version !== '0.9.26'
            || ($genome['genome_id'] ?? '') === 'kicom-0.9.26-g25r3';
        $healthy = $lineageOk && ($genome['version'] ?? '') === $version
            && ($genome['healthy'] ?? '') === 'true'
            && ($genome['trusted'] ?? '') === 'true'
            && ($genome['lkg_ok'] ?? '') === 'true'
            && ($genome['drift_count'] ?? '') === '0'
            && ($genome['unknown_count'] ?? '') === '0';
        $this->pam->observe('KiCom:' . $version, 'genome-integrity', $healthy ? 'AVAILABLE' : 'DEGRADED',
            'KCL:GENOME_STATUS', $evidence['GENOME_STATUS'], 300);

        $sqlite = $facts['SQLITE_STATUS'];
        $dbHealthy = ($sqlite['primary'] ?? '') === 'true'
            && ($sqlite['quick_check'] ?? '') === 'ok'
            && ($sqlite['journal_mode'] ?? '') === 'wal';
        $this->pam->observe('KiCom:' . $version, 'sqlite-quick-check', $dbHealthy ? 'AVAILABLE' : 'DEGRADED',
            'KCL:SQLITE_STATUS', $evidence['SQLITE_STATUS'], 300);

        $update = $facts['UPDATE_STATUS'];
        $pending = $update['pending_version'] ?? '';
        $risk = strtolower($update['pending_risk'] ?? '');
        $red = $pending !== '' && $risk === 'red';
        $pendingKnown = array_key_exists('pending_version', $update)
            && array_key_exists('pending_risk', $update)
            && array_key_exists('pending_source', $update);
        $pendingConsistent = $pendingKnown
            && (($pending === '' && $risk === '' && $update['pending_source'] === '')
                || ($pending !== '' && $risk !== '' && $update['pending_source'] !== ''));
        $this->pam->observe('KiCom:' . $version, 'production-install',
            $red ? 'FORBIDDEN' : 'UNKNOWN',
            'KCL:UPDATE_STATUS', $evidence['UPDATE_STATUS'], 60);
        $this->pam->observe('KiCom:' . $version, 'pending-update-consistency',
            $pendingConsistent ? 'AVAILABLE' : 'DEGRADED',
            'KCL:UPDATE_STATUS', $evidence['UPDATE_STATUS'], 60);

        $normal = [
            'version' => $version, 'genome_healthy' => $healthy,
            'sqlite_quick_check' => $dbHealthy, 'genome_id' => $genome['genome_id'] ?? '',
            'pending_consistent' => $pendingConsistent, 'pending_version' => $pending,
            'pending_risk' => $risk, 'pending_source' => $update['pending_source'] ?? ''
        ];
        $stateSha = hash('sha256', json_encode($normal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $summary = sprintf('KiCom %s genome=%s sqlite=%s pending=%s risk=%s',
            $version, $healthy ? 'ok' : 'degraded', $dbHealthy ? 'ok' : 'degraded',
            $pending === '' ? 'none' : $pending, $risk === '' ? 'none' : $risk);
        $checkpointId = $this->pam->checkpoint($runKey, $stateSha, $summary);

        if ($pending !== '' && $risk === 'red') {
            // Investigation only. No production-install task is executable here.
            $this->pam->queue('review-release-' . $pending,
                'Verify immutable candidate identity before any protected release',
                hash('sha256', $pending . "\n" . $risk), 'internal');
        }
        return [
            'checkpoint_id' => $checkpointId, 'state_sha256' => $stateSha,
            'version' => $version, 'genome_healthy' => $healthy,
            'sqlite_quick_check' => $dbHealthy, 'genome_id' => $genome['genome_id'] ?? '',
            'pending_consistent' => $pendingConsistent,
            'protected_install_pending' => $red
        ];
    }
}
