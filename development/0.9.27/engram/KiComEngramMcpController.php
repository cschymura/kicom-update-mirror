<?php
declare(strict_types=1);

require_once __DIR__ . '/KiComEngramStore.php';
require_once __DIR__ . '/KiComEngramMcpContract.php';

/**
 * DEV-only in-process facade. No HTTP route and no authenticator.
 * Identity and apiState must come from already verified server-side state.
 */
final class KiComEngramMcpController
{
    public function __construct(
        private KiComEngramStore $store,
        private KiComEngramMcpContract $contract,
        private string $owner,
        private string $apiState = 'inactive'
    ) {
        if (!preg_match('/\A[a-z0-9][a-z0-9._:-]{2,63}\z/D', $owner)) {
            throw new InvalidArgumentException('owner');
        }
    }

    public function dispatch(array $request, array $identity, int $now): array
    {
        $accepted = $this->contract->accept($request, $identity, $this->apiState, $now);
        if ($accepted['op'] === 'read') {
            $rows = $this->store->search(
                $this->owner,
                $accepted['namespace'],
                $request['query'],
                $accepted['limit']
            );
            $minimal = array_map(static fn(array $r): array => [
                'id' => (string)$r['id'],
                'revision' => (int)$r['revision'],
                'body' => (string)$r['body'],
                'source_kind' => (string)$r['source_kind'],
            ], $rows);
            return ['status' => 'SYNTHETIC_READ_OK'] + $this->contract->projectRead($minimal, $accepted['limit']);
        }
        if ($accepted['op'] === 'append') {
            if ($request['query'] === '') {
                throw new RuntimeException('APPEND_BODY_REQUIRED');
            }
            $created = $this->store->create(
                $this->owner,
                $accepted['namespace'],
                'technical',
                $request['query'],
                'synthetic_test',
                'mcp://synthetic-controller'
            );
            return [
                'status' => 'SYNTHETIC_APPEND_OK',
                'id' => $created['id'],
                'revision' => $created['revision'],
            ];
        }
        throw new RuntimeException('UNREACHABLE_OPERATION');
    }
}
