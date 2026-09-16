<?php
declare(strict_types=1);

require_once __DIR__.'/DevRouter.php';

/**
 * HTTP transport adapter for the DEV router.
 * Credentials are accepted from headers only by default.
 */
final class KiComDevHttpAdapter
{
    private KiComDevRouter $router;

    public function __construct(KiComDevRouter $router)
    {
        $this->router = $router;
    }

    /**
     * Pure request handler for easy testing. The caller owns HTTP status/header output.
     *
     * @param array<string,mixed> $server
     */
    public function handle(array $server, string $rawBody): array
    {
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? ''));
        if ($method !== 'POST') {
            return ['http_status'=>405, 'body'=>['ok'=>false, 'code'=>'DEV_POST_REQUIRED']];
        }

        $contentType = strtolower((string)($server['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            return ['http_status'=>415, 'body'=>['ok'=>false, 'code'=>'DEV_JSON_REQUIRED']];
        }

        if (strlen($rawBody) > 1048576) {
            return ['http_status'=>413, 'body'=>['ok'=>false, 'code'=>'DEV_BODY_TOO_LARGE']];
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return ['http_status'=>400, 'body'=>['ok'=>false, 'code'=>'DEV_JSON_INVALID']];
        }

        $sid = strtolower(trim((string)($server['HTTP_X_KICOM_DEV_SESSION'] ?? '')));
        $token = strtolower(trim((string)($server['HTTP_X_KICOM_DEV_TOKEN'] ?? '')));
        if ($sid === '' || $token === '') {
            return ['http_status'=>401, 'body'=>['ok'=>false, 'code'=>'DEV_CREDENTIAL_REQUIRED']];
        }

        $operation = strtoupper(trim((string)($data['operation'] ?? '')));
        $payload = $data['payload'] ?? [];
        if ($operation === '' || !is_array($payload)) {
            return ['http_status'=>400, 'body'=>['ok'=>false, 'code'=>'DEV_REQUEST_INVALID']];
        }

        $result = $this->router->handle($operation, $sid, $token, $payload);
        $ok = !empty($result['ok']);
        $code = (string)($result['code'] ?? 'DEV_UNKNOWN');
        $status = 200;
        if (!$ok) {
            if (str_starts_with($code, 'DEV_SESSION_') || str_starts_with($code, 'DEV_CREDENTIAL_')) $status = 401;
            elseif ($code === 'DEV_OPERATION_FORBIDDEN' || $code === 'DEV_CAPABILITY_FORBIDDEN') $status = 403;
            elseif ($code === 'DEV_OPERATION_NOT_IMPLEMENTED') $status = 501;
            else $status = 422;
        }
        return ['http_status'=>$status, 'body'=>$result];
    }
}
