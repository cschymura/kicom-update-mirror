<?php
declare(strict_types=1);

require_once __DIR__.'/DevSession.php';

/**
 * Dedicated KiCom DEV router.
 *
 * This class deliberately knows only DEV capabilities. Production, recovery,
 * self-update and secret-management callbacks cannot be registered here.
 */
final class KiComDevRouter
{
    /** @var array<string,string> */
    private const OP_CAPABILITY = [
        'DEV_SOURCE_SNAPSHOT' => 'source.snapshot.read',
        'DEV_WORKSPACE_READ' => 'workspace.read',
        'DEV_WORKSPACE_WRITE' => 'workspace.write',
        'DEV_WORKSPACE_DELETE' => 'workspace.delete',
        'DEV_WORKSPACE_HISTORY' => 'workspace.history',
        'DEV_BUILD_BEGIN' => 'build.begin',
        'DEV_BUILD_PATCH' => 'build.patch',
        'DEV_BUILD_STATUS' => 'build.status',
        'DEV_BUILD_TEST' => 'build.test',
        'DEV_BUILD_FINALIZE_CANDIDATE' => 'build.finalize_candidate',
        'DEV_CANDIDATE_READ' => 'candidate.read',
        'DEV_CANDIDATE_DISCARD' => 'candidate.discard',
        'DEV_LOG_READ' => 'logs.read',
    ];

    private KiComDevSessionManager $sessions;
    /** @var array<string,Closure> */
    private array $handlers = [];

    public function __construct(KiComDevSessionManager $sessions, array $handlers = [])
    {
        $this->sessions = $sessions;
        foreach ($handlers as $operation => $handler) {
            $this->register((string)$operation, $handler);
        }
    }

    public function register(string $operation, callable $handler): void
    {
        $operation = strtoupper(trim($operation));
        if (!isset(self::OP_CAPABILITY[$operation])) {
            throw new InvalidArgumentException('DEV_OPERATION_FORBIDDEN');
        }
        $this->handlers[$operation] = Closure::fromCallable($handler);
    }

    public function handle(string $operation, string $sessionId, string $token, array $payload = []): array
    {
        $operation = strtoupper(trim($operation));

        if ($operation === 'DEV_SESSION_STATUS') {
            $auth = $this->sessions->authenticate($sessionId, $token, null);
            if (empty($auth['ok'])) return $auth;
            return $this->sessions->publicStatus($sessionId);
        }
        if ($operation === 'DEV_SESSION_REVOKE') {
            $auth = $this->sessions->authenticate($sessionId, $token, null);
            if (empty($auth['ok'])) return $auth;
            return $this->sessions->revoke($sessionId, (string)($payload['reason'] ?? 'self-revoke'));
        }

        $capability = self::OP_CAPABILITY[$operation] ?? null;
        if ($capability === null) {
            return ['ok' => false, 'code' => 'DEV_OPERATION_FORBIDDEN'];
        }
        if (!KiComDevSessionManager::capabilityDefined($capability)) {
            return ['ok' => false, 'code' => 'DEV_CAPABILITY_FORBIDDEN'];
        }

        $auth = $this->sessions->authenticate($sessionId, $token, $capability);
        if (empty($auth['ok'])) return $auth;

        $handler = $this->handlers[$operation] ?? null;
        if (!$handler instanceof Closure) {
            return ['ok' => false, 'code' => 'DEV_OPERATION_NOT_IMPLEMENTED'];
        }

        try {
            $result = $handler($payload, $auth);
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'DEV_OPERATION_FAILED'];
        }
        if (!is_array($result)) {
            return ['ok' => false, 'code' => 'DEV_HANDLER_INVALID'];
        }
        return $result + [
            'operation' => $operation,
            'capability' => $capability,
            'scope' => 'dev',
        ];
    }

    /** @return array<string,string> */
    public static function operationCapabilities(): array
    {
        return self::OP_CAPABILITY;
    }
}
