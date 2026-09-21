<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramHostingPolicy.php';

/**
 * DEV-44: server-side, fail-closed MCP runtime authorization boundary.
 *
 * No HTTP route, login method, passkey enrollment, activation operation or
 * config writer. Call ONLY after existing KiCom middleware has authenticated
 * the connector and loaded server-owned host policy, private owner registry
 * and activation DB. NEVER pass client JSON to $runtime or $connector.
 *
 * Inactive KiCom 0.9.29 configs intentionally lack the MCP policy fields and
 * fail before any store or adapter can be constructed.
 */
final class KiComEngramMcpRuntimeGate
{
    private const OWNER = 'mirage-owner';

    public static function authorize(
        array $runtime,
        PDO $activationDb,
        callable $lookupOwner,
        array $verifiedConnector
    ): array {
        self::exact($verifiedConnector, [
            'authenticated', 'connector_id', 'credential_fingerprint',
            'owner_binding', 'host_evidence_id'
        ]);
        // This exact server-side policy is an explicit, separately reviewed
        // *future* config extension; the current scaffold does not supply it.
        foreach (['enabled','operator_approved',
                  'review_enabled','mcp_connector_enabled'] as $flag) {
            if (($runtime[$flag] ?? null) !== true) self::deny();
        }
        if (!KiComEngramHostingPolicy::permits($runtime)) self::deny();
        if (($runtime['runtime_source'] ?? null) !== 'server-only-reviewed'
            || ($runtime['private_memory_scope'] ?? null) !== 'dev-verified-owner'
            || ($runtime['admin_subject'] ?? null) !== self::OWNER
            || ($runtime['expected_origin'] ?? null) !== 'https://kicom.rurtalbahn.info'
            || ($runtime['rp_id'] ?? null) !== 'kicom.rurtalbahn.info') self::deny();
        foreach (['mcp_connector_id','owner_binding','host_evidence_id'] as $key) {
            if (!is_string($runtime[$key] ?? null)) self::deny();
        }
        $connectorId = $runtime['mcp_connector_id'];
        $ownerBinding = $runtime['owner_binding'];
        $hostId = $runtime['host_evidence_id'];
        if (!preg_match('/\A[a-z0-9][a-z0-9._:-]{2,63}\z/D', $connectorId)
            || !self::hex64($ownerBinding) || !self::hex64($hostId)) self::deny();
        // Connector identity must be supplied by an authenticated server-side
        // trust boundary, not inferred from any MCP operation or JSON body.
        if (($verifiedConnector['authenticated'] ?? null) !== true
            || !is_string($verifiedConnector['connector_id'] ?? null)
            || !hash_equals($connectorId, $verifiedConnector['connector_id'])
            || !is_string($verifiedConnector['owner_binding'] ?? null)
            || !hash_equals($ownerBinding, $verifiedConnector['owner_binding'])
            || !is_string($verifiedConnector['host_evidence_id'] ?? null)
            || !hash_equals($hostId, $verifiedConnector['host_evidence_id'])) self::deny();
        $fp = $verifiedConnector['credential_fingerprint'] ?? null;
        if (!self::hex64($fp)) self::deny();

        // Read-only. Do NOT call DEV-40 state() here: that test helper creates
        // tables. Missing activation state must fail without mutation.
        try {
            if ($activationDb->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') self::deny();
            $rows = $activationDb->query(
                'SELECT state, owner_binding, host_evidence_id FROM activation_state WHERE singleton=1'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            self::deny();
        }
        if (count($rows) !== 1 || ($rows[0]['state'] ?? null) !== 'active'
            || !is_string($rows[0]['owner_binding'] ?? null)
            || !hash_equals($ownerBinding, $rows[0]['owner_binding'])
            || !is_string($rows[0]['host_evidence_id'] ?? null)
            || !hash_equals($hostId, $rows[0]['host_evidence_id'])) self::deny();

        // Recheck revocation against the CURRENT private owner registry on
        // every request. Never accept an owner, rights or fingerprint from MCP JSON.
        try { $owner = $lookupOwner($fp); }
        catch (Throwable) { self::deny(); }
        if (!is_array($owner) || ($owner['enabled'] ?? null) !== true
            || ($owner['credential_fingerprint'] ?? null) !== $fp
            || ($owner['subject'] ?? null) !== self::OWNER
            || ($owner['namespaces'] ?? null) !== ['project']) self::deny();
        $rights = $owner['engram_rights'] ?? null;
        if (!is_array($rights) || !in_array('engram.read', $rights, true)) self::deny();
        // Bind the approval to the exact currently verified passkey identity.
        if (!hash_equals($ownerBinding, hash('sha256', self::OWNER . "\0" . $fp))) self::deny();

        return [
            'authenticated' => true,
            'owner' => self::OWNER,
            'connector_id' => $connectorId,
        ];
    }

    /**
     * Initial live adapter mode is READ-ONLY. Writing requires the separate
     * exact-content, one-use KiCom passkey approval flow, which is not wired
     * through this synthetic MCP adapter yet.
     * Private in-process entrypoint. The trusted factory is invoked ONLY after
     * checking live runtime policy, activation, connector and owner revocation.
     * This function is not a public MCP route or an authenticator.
     */
    public static function dispatch(
        string $wire,
        array $runtime,
        PDO $activationDb,
        callable $lookupOwner,
        array $verifiedConnector,
        int $now,
        callable $adapterFactory
    ): string {
        try {
            $identity = self::authorize($runtime, $activationDb, $lookupOwner, $verifiedConnector);
            if ($wire === '' || strlen($wire) > 2048) self::deny();
            $parsed = json_decode($wire, true, 16, JSON_THROW_ON_ERROR);
            // The actual owner registry allows ONLY the project namespace.
            if (!is_array($parsed) || array_is_list($parsed)
                || ($parsed['namespace'] ?? null) !== 'project'
                || ($parsed['op'] ?? null) !== 'read') self::deny();
            $adapter = $adapterFactory($identity);
            if (!$adapter instanceof KiComEngramMcpJsonAdapter) self::deny();
            return $adapter->handle($wire, $identity, $now);
        } catch (Throwable) {
            // The connector cannot infer which particular boundary failed.
            return '{"ok":false,"error":"REQUEST_DENIED"}';
        }
    }

    private static function hex64(mixed $v): bool
    {
        return is_string($v) && preg_match('/\A[a-f0-9]{64}\z/D', $v) === 1;
    }

    private static function exact(array $data, array $keys): void
    {
        $a = array_keys($data); sort($a, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($a !== $keys) self::deny();
    }

    private static function deny(): never
    {
        // Never leak whether config, token, UID, registry or activation failed.
        throw new RuntimeException('MCP_RUNTIME_INACTIVE_OR_UNAUTHORIZED');
    }
}
