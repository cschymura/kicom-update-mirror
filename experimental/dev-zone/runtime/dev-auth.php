<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root.'/lib.php';
require_once __DIR__.'/PasskeyBridge.php';
require_once __DIR__.'/DevSession.php';
require_once __DIR__.'/DevAuthAdapter.php';
require_once __DIR__.'/DevAuthFlow.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function devAuthOut(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    devAuthOut(['ok'=>false,'code'=>'DEV_POST_REQUIRED'], 405);
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 262144) devAuthOut(['ok'=>false,'code'=>'DEV_BODY_INVALID'], 413);
$data = json_decode($raw, true);
if (!is_array($data)) devAuthOut(['ok'=>false,'code'=>'DEV_JSON_INVALID'], 400);
$op = strtoupper(trim((string)($data['operation'] ?? '')));
$payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

$store = kicomVarDir().'/dev_zone';
$passkeys = new KiComPasskeyBridge($store.'/passkeys');
$sessions = new KiComDevSessionManager($store.'/sessions');
$adapter = new KiComDevAuthAdapter($passkeys, $sessions, $store.'/locks');
$flow = new KiComDevAuthFlow($passkeys, $adapter);

switch ($op) {
    case 'DEV_READY':
        $p = $passkeys->ready();
        $s = $sessions->ready();
        devAuthOut([
            'ok'=>!empty($p['ok']) && !empty($s['ok']),
            'code'=>(!empty($p['ok']) && !empty($s['ok'])) ? 'DEV_READY' : 'DEV_NOT_READY',
            'passkey_ready'=>!empty($p['ok']),
            'session_ready'=>!empty($s['ok']),
            'session_ttl'=>$s['ttl'] ?? 0,
            'idle_ttl'=>$s['idle_ttl'] ?? 0,
        ], (!empty($p['ok']) && !empty($s['ok'])) ? 200 : 503);

    case 'DEV_ENROLL_BEGIN':
        if (!function_exists('kicomTotpVerifyConsume')) devAuthOut(['ok'=>false,'code'=>'TOTP_VERIFIER_UNAVAILABLE'], 503);
        $code = preg_replace('/\D+/', '', (string)($payload['code'] ?? ''));
        $v = kicomTotpVerifyConsume($code, 'dev_passkey_enrollment');
        if (empty($v['ok'])) devAuthOut(['ok'=>false,'code'=>(string)($v['code'] ?? 'TOTP_CODE_REJECTED')], 401);
        $r = $passkeys->createEnrollmentTicket((string)($payload['account'] ?? 'Christoph'));
        devAuthOut($r, !empty($r['ok']) ? 200 : 422);

    case 'DEV_ENROLL_OPTIONS':
        $r = $passkeys->registrationOptions((string)($payload['enrollment_id'] ?? ''));
        devAuthOut($r, !empty($r['ok']) ? 200 : 422);

    case 'DEV_ENROLL_COMPLETE':
        $credential = is_array($payload['credential'] ?? null) ? $payload['credential'] : [];
        $r = $passkeys->completeRegistration(
            (string)($payload['enrollment_id'] ?? ''),
            $credential,
            (string)($payload['label'] ?? 'KiCom DEV Passkey')
        );
        devAuthOut($r, !empty($r['ok']) ? 200 : 422);

    case 'DEV_AUTH_BEGIN':
        $r = $flow->begin();
        devAuthOut($r, !empty($r['ok']) ? 200 : 422);

    case 'DEV_AUTH_OPTIONS':
        $r = $flow->options((string)($payload['challenge_id'] ?? ''));
        devAuthOut($r, !empty($r['ok']) ? 200 : 422);

    case 'DEV_AUTH_COMPLETE':
        $credential = is_array($payload['credential'] ?? null) ? $payload['credential'] : [];
        $r = $flow->complete(
            (string)($payload['challenge_id'] ?? ''),
            $credential,
            (string)($payload['label'] ?? 'KiCom DEV')
        );
        devAuthOut($r, !empty($r['ok']) ? 200 : 401);

    default:
        devAuthOut(['ok'=>false,'code'=>'DEV_AUTH_OPERATION_UNKNOWN'], 400);
}
