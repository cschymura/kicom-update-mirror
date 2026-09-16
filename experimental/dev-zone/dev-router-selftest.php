<?php
declare(strict_types=1);
require_once __DIR__.'/DevRouter.php';

function failr(string $m): never { fwrite(STDERR, "FAIL: $m\n"); exit(1); }
function okr(bool $v, string $m): void { if (!$v) failr($m); echo "OK: $m\n"; }

$dir = sys_get_temp_dir().'/kicom-dev-router-'.bin2hex(random_bytes(4));
$sessions = new KiComDevSessionManager($dir, 7200, 3600);
$issued = $sessions->issue(['auth_method'=>'selftest','label'=>'router']);
okr(($issued['ok'] ?? false) === true, 'session issued');
$sid = (string)$issued['session_id'];
$token = (string)$issued['token'];

$calls = [];
$router = new KiComDevRouter($sessions, [
    'DEV_WORKSPACE_READ' => function(array $payload, array $auth) use (&$calls): array {
        $calls[] = ['read', $payload, $auth['scope'] ?? ''];
        return ['ok'=>true, 'code'=>'READ_OK', 'path'=>(string)($payload['path'] ?? '')];
    },
    'DEV_BUILD_TEST' => function(array $payload, array $auth) use (&$calls): array {
        $calls[] = ['test', $payload, $auth['scope'] ?? ''];
        return ['ok'=>true, 'code'=>'TEST_OK'];
    },
]);

$r = $router->handle('DEV_WORKSPACE_READ', $sid, $token, ['path'=>'prototype/a.php']);
okr(($r['ok'] ?? false) === true && ($r['code'] ?? '') === 'READ_OK', 'allowed operation executes');
okr(($r['capability'] ?? '') === 'workspace.read', 'exact capability reported');
okr(count($calls) === 1, 'handler called once');

$r = $router->handle('DEV_BUILD_TEST', $sid, $token, ['build_id'=>'x']);
okr(($r['ok'] ?? false) === true && count($calls) === 2, 'same token reusable across DEV requests');

$r = $router->handle('SELF_UPDATE_INSTALL', $sid, $token, []);
okr(($r['ok'] ?? true) === false && ($r['code'] ?? '') === 'DEV_OPERATION_FORBIDDEN', 'production-style operation forbidden');
okr(count($calls) === 2, 'forbidden operation never reaches handler');

$r = $router->handle('DEV_WORKSPACE_WRITE', $sid, $token, []);
okr(($r['ok'] ?? true) === false && ($r['code'] ?? '') === 'DEV_OPERATION_NOT_IMPLEMENTED', 'unimplemented DEV operation fails closed');

$r = $router->handle('DEV_WORKSPACE_READ', $sid, str_repeat('0', 64), []);
okr(($r['ok'] ?? true) === false && ($r['code'] ?? '') === 'DEV_SESSION_TOKEN_REJECTED', 'bad token rejected');
okr(count($calls) === 2, 'bad token never reaches handler');

$r = $router->handle('DEV_SESSION_STATUS', $sid, $token, []);
okr(($r['ok'] ?? false) === true && ($r['state'] ?? '') === 'active', 'authenticated status works');

$r = $router->handle('DEV_SESSION_REVOKE', $sid, $token, ['reason'=>'selftest complete']);
okr(($r['ok'] ?? false) === true, 'self revoke works');
$r = $router->handle('DEV_WORKSPACE_READ', $sid, $token, []);
okr(($r['ok'] ?? true) === false && ($r['code'] ?? '') === 'DEV_SESSION_REVOKED', 'revoked token cannot execute');

$thrown = false;
try {
    $router->register('DEV_PRODUCTION_WRITE', static fn(): array => ['ok'=>true]);
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
okr($thrown, 'unknown callback cannot be registered');

echo "DEV ROUTER SELFTEST PASS\n";
