<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramStore.php';
require_once __DIR__ . '/KiComEngramIngestionGate.php';

/**
 * Isolated DEV connector-facing memory roundtrip, never a public HTTP route.
 *
 * The actual KiCom server must supply trustedIdentity from an independently
 * authenticated server-side session and consumeApproval from a one-time,
 * exact-record approval issuer. Neither may originate from client payload.
 * Constructing this class is NOT proof of a live KiCom memory integration.
 */
final class KiComEngramAuthenticatedBridge
{
    private KiComEngramStore $store;
    private $trustedIdentity;
    private KiComEngramIngestionGate $ingestGate;
    private ?array $ingestIdentity = null;

    public function __construct(
        KiComEngramStore $store,
        callable $trustedIdentity,
        callable $consumeApproval
    ) {
        $this->store = $store;
        $this->trustedIdentity = $trustedIdentity;
        // Pin the identity for this individual write, avoiding a second,
        // potentially changed caller identity between authorization and insert.
        $this->ingestGate = new KiComEngramIngestionGate(
            $store,
            fn(): array => $this->ingestIdentity ?? ['verified' => false],
            $consumeApproval
        );
    }

    private function authorize(string $namespace, string $permission): array
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $namespace)) {
            throw new RuntimeException('ENGRAM_BRIDGE_NAMESPACE_FORBIDDEN');
        }
        $actor = ($this->trustedIdentity)();
        if (!is_array($actor) || ($actor['verified'] ?? null) !== true
            || !is_string($actor['subject'] ?? null)
            || !preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $actor['subject'])
            || !is_array($actor['namespaces'] ?? null)
            || !in_array($namespace, $actor['namespaces'], true)
            || !is_array($actor['engram_rights'] ?? null)
            || !in_array($permission, $actor['engram_rights'], true)) {
            throw new RuntimeException('ENGRAM_BRIDGE_AUTH_FORBIDDEN');
        }
        return $actor;
    }

    /** An exact-record, one-time trusted consent callback is mandatory. */
    public function remember(array $entry): array
    {
        $namespace = $entry['namespace'] ?? null;
        if (!is_string($namespace)) {
            throw new RuntimeException('ENGRAM_BRIDGE_NAMESPACE_FORBIDDEN');
        }
        if ($this->ingestIdentity !== null) {
            throw new RuntimeException('ENGRAM_BRIDGE_REENTRANT_WRITE_FORBIDDEN');
        }
        $actor = $this->authorize($namespace, 'engram.write');
        $this->ingestIdentity = $actor;
        try {
            return $this->ingestGate->ingest($entry);
        } finally {
            $this->ingestIdentity = null;
        }
    }

    /**
     * Query is bounded and scoped to a verified server-side identity.
     * Unknown client keys, in particular subject/account/session/actor, fail
     * closed rather than silently accepting a request-scoped impersonation.
     */
    public function recall(array $request): array
    {
        $keys = array_keys($request);
        sort($keys);
        if ($keys !== ['limit', 'namespace', 'query']
            || !is_string($request['namespace'])
            || !is_string($request['query'])
            || !is_int($request['limit'])
            || $request['limit'] < 1 || $request['limit'] > 10
            || strlen($request['query']) > 128 || $request['query'] === '') {
            throw new RuntimeException('ENGRAM_BRIDGE_QUERY_INVALID');
        }
        $actor = $this->authorize($request['namespace'], 'engram.read');
        $records = $this->store->search(
            $actor['subject'], $request['namespace'],
            $request['query'], $request['limit']
        );
        return [
            'ok' => true,
            'namespace' => $request['namespace'],
            'records' => $records,
            'count' => count($records),
        ];
    }
}
