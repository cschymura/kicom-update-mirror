<?php
declare(strict_types=1);

/**
 * DEV-only readiness gate. It cannot activate Engram and never handles secrets,
 * paths, passkeys or private memory. Inputs must come from authenticated,
 * server-side verified evidence, never request parameters.
 */
final class KiComEngramActivationReadinessGate
{
    /** @return array{ready:bool,status:string,owner_binding:string,host_evidence_id:string} */
    public static function evaluate(
        array $hostEvidence,
        array $ownerEvidence,
        string $expectedOwnerBinding,
        DateTimeImmutable $nowUtc,
        int $maxEvidenceAgeSeconds = 900
    ): array {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $expectedOwnerBinding)) {
            throw new InvalidArgumentException('Expected owner binding must be SHA-256 hex');
        }
        if ($maxEvidenceAgeSeconds < 60 || $maxEvidenceAgeSeconds > 3600) {
            throw new InvalidArgumentException('Evidence age policy out of bounds');
        }
        self::assertExactKeys($hostEvidence, ['eligible','status','evidence_id','checked_at_utc']);
        self::assertExactKeys($ownerEvidence, ['schema','verified','owner_binding','host_evidence_id','checked_at_utc','private_api_inactive','mcp_connector_connected']);
        if (($hostEvidence['eligible'] ?? null) !== true
            || ($hostEvidence['status'] ?? null) !== 'HOST_ISOLATION_EVIDENCE_COMPLETE_API_STILL_INACTIVE') {
            throw new RuntimeException('Host isolation evidence not eligible');
        }
        $hostId = $hostEvidence['evidence_id'] ?? null;
        if (!is_string($hostId) || !preg_match('/\A[a-f0-9]{64}\z/D', $hostId)) {
            throw new RuntimeException('Invalid host evidence id');
        }
        if (($ownerEvidence['schema'] ?? null) !== 'mirage-owner-readiness/v1'
            || ($ownerEvidence['verified'] ?? null) !== true) {
            throw new RuntimeException('Owner evidence not verified');
        }
        $ownerBinding = $ownerEvidence['owner_binding'] ?? null;
        if (!is_string($ownerBinding) || !preg_match('/\A[a-f0-9]{64}\z/D', $ownerBinding)
            || !hash_equals($expectedOwnerBinding, $ownerBinding)) {
            throw new RuntimeException('Owner binding mismatch');
        }
        if (!is_string($ownerEvidence['host_evidence_id'] ?? null)
            || !hash_equals($hostId, $ownerEvidence['host_evidence_id'])) {
            throw new RuntimeException('Owner evidence belongs to different host evidence');
        }
        if (($ownerEvidence['private_api_inactive'] ?? null) !== true
            || ($ownerEvidence['mcp_connector_connected'] ?? null) !== false) {
            throw new RuntimeException('Readiness must precede API and MCP activation');
        }
        self::assertFreshUtc($hostEvidence['checked_at_utc'] ?? null, $nowUtc, $maxEvidenceAgeSeconds, 'host');
        self::assertFreshUtc($ownerEvidence['checked_at_utc'] ?? null, $nowUtc, $maxEvidenceAgeSeconds, 'owner');
        return [
            'ready' => true,
            'status' => 'AUTHENTICATED_ACTIVATION_READY_API_STILL_INACTIVE',
            'owner_binding' => $ownerBinding,
            'host_evidence_id' => $hostId,
        ];
    }

    private static function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING); sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('Unknown or missing readiness evidence field');
        }
    }

    private static function assertFreshUtc(mixed $value, DateTimeImmutable $nowUtc, int $maxAge, string $label): void
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value)) {
            throw new RuntimeException('Invalid ' . $label . ' evidence timestamp');
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('Impossible ' . $label . ' evidence timestamp');
        }
        $now = $nowUtc->setTimezone(new DateTimeZone('UTC'));
        $age = $now->getTimestamp() - $time->getTimestamp();
        if ($age < 0 || $age > $maxAge) {
            throw new RuntimeException('Stale or future ' . $label . ' evidence');
        }
    }
}
