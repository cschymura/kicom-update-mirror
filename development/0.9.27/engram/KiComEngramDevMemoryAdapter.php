<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramAuthenticatedBridge.php';

/**
 * REVIEW-ONLY DEV memory transport: NOT a publicly registered HTTP endpoint.
 *
 * Installation code MUST inject independently trusted server-side callbacks:
 * identityForSession($authenticatedSession) -> verified subject, namespaces,
 *   explicit engram.read/engram.write permissions from authoritative storage;
 * consumeApproval($exactBinding) -> atomic single-use, independently issued
 *   and authenticated per-record consent;
 * openPrivateStore() -> private KiComEngramStore from verified non-webroot paths.
 *
 * The legacy DEV session authenticates the caller but does NOT identify a
 * private Engram owner or grant Engram rights. No fallback to session label,
 * HTTP-provided subject, Slack text, repository content or anonymous access.
 */
final class KiComEngramDevMemoryAdapter
{
    private KiComDevSessionManager $sessions;
    private $identityForSession;
    private $consumeApproval;
    private $openPrivateStore;

    public function __construct(
        KiComDevSessionManager $sessions,
        callable $identityForSession,
        callable $consumeApproval,
        callable $openPrivateStore
    ) {
        $this->sessions = $sessions;
        $this->identityForSession = $identityForSession;
        $this->consumeApproval = $consumeApproval;
        $this->openPrivateStore = $openPrivateStore;
    }

    /** Pure isolated HTTP-like handler. Call ONLY from a reviewed KiCom route. */
    public function handle(array $server, string $rawBody): array
    {
        $headers = [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Content-Type' => 'application/json; charset=utf-8',
        ];
        $reply = static fn(int $status, array $body): array => [
            'http_status' => $status, 'headers' => $headers, 'body' => $body,
        ];
        if (strtoupper((string)($server['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return $reply(405, ['ok' => false, 'code' => 'ENGRAM_POST_REQUIRED']);
        }
        if (!preg_match('/\\Aapplication\\/json(?:\\s*;\\s*charset=utf-8)?\\z/i',
            trim((string)($server['CONTENT_TYPE'] ?? '')))) {
            return $reply(415, ['ok' => false, 'code' => 'ENGRAM_JSON_REQUIRED']);
        }
        if (strlen($rawBody) > 16384) {
            return $reply(413, ['ok' => false, 'code' => 'ENGRAM_BODY_TOO_LARGE']);
        }

        // Only DEV bearer headers authenticate transport. Client payload is
        // NEVER an identity, permission, consent receipt or private path.
        $sid = (string)($server['HTTP_X_KICOM_DEV_SESSION'] ?? '');
        $token = (string)($server['HTTP_X_KICOM_DEV_TOKEN'] ?? '');
        if (!preg_match('/\\A[a-f0-9]{24}\\z/D', $sid)
            || !preg_match('/\\A[a-f0-9]{64}\\z/D', $token)) {
            return $reply(401, ['ok' => false, 'code' => 'ENGRAM_CREDENTIAL_REQUIRED']);
        }
        $auth = $this->sessions->authenticate($sid, $token, null);
        if (empty($auth['ok'])) {
            return $reply(401, ['ok' => false, 'code' => 'ENGRAM_AUTH_DENIED']);
        }

        $input = json_decode($rawBody, true);
        if (!is_array($input) || array_is_list($input)) {
            return $reply(400, ['ok' => false, 'code' => 'ENGRAM_REQUEST_INVALID']);
        }
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['operation', 'payload']
            || !is_string($input['operation'])
            || !is_array($input['payload']) || array_is_list($input['payload'])) {
            return $reply(400, ['ok' => false, 'code' => 'ENGRAM_REQUEST_INVALID']);
        }
        $operation = $input['operation'];
        if ($operation !== 'ENGRAM_RECALL' && $operation !== 'ENGRAM_REMEMBER') {
            return $reply(403, ['ok' => false, 'code' => 'ENGRAM_OPERATION_FORBIDDEN']);
        }
        $payload = $input['payload'];

        try {
            $actor = ($this->identityForSession)($auth);
            if (!is_array($actor) || ($actor['verified'] ?? null) !== true
                || !is_string($actor['subject'] ?? null)
                || !is_array($actor['namespaces'] ?? null)
                || !is_array($actor['engram_rights'] ?? null)) {
                return $reply(403, ['ok' => false, 'code' => 'ENGRAM_IDENTITY_FORBIDDEN']);
            }
            // The authenticating server must make this a single fixed subject
            // for this request, not a new client-selectable subject on each call.
            $identity = static fn(): array => $actor;
            $store = ($this->openPrivateStore)();
            if (!$store instanceof KiComEngramStore) {
                throw new RuntimeException('Private Engram store unavailable');
            }
            $bridge = new KiComEngramAuthenticatedBridge(
                $store, $identity, $this->consumeApproval
            );
            if ($operation === 'ENGRAM_RECALL') {
                $out = $bridge->recall($payload);
                return $reply(200, ['ok' => true, 'code' => 'ENGRAM_RECALL_OK'] + $out);
            }
            $out = $bridge->remember($payload);
            return $reply(200, ['ok' => true, 'code' => 'ENGRAM_REMEMBER_OK'] + $out);
        } catch (InvalidArgumentException|RuntimeException $error) {
            // No exception, path, source, private content or consent detail is
            // reflected to the caller. Do not log raw request/response elsewhere.
            return $reply(403, ['ok' => false, 'code' => 'ENGRAM_REQUEST_DENIED']);
        } catch (Throwable $error) {
            return $reply(503, ['ok' => false, 'code' => 'ENGRAM_UNAVAILABLE']);
        }
    }
}
