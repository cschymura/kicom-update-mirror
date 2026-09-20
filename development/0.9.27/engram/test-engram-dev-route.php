<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramDevCandidatePatcher.php';
require_once __DIR__ . '/KiComEngramDevPathHandler.php';

$routeChecks = 0;
function routeCheck(bool $condition, string $label): void {
    global $routeChecks;
    if (!$condition) { throw new RuntimeException('FAIL ' . $label); }
    $routeChecks++;
    echo 'PASS ' . $label . "\n";
}
function routeReject(callable $operation, string $label): void {
    $rejected = false;
    try { $operation(); } catch (InvalidArgumentException|RuntimeException $e) { $rejected = true; }
    routeCheck($rejected, $label);
}
function routeClean(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') { routeClean($path . '/' . $entry); }
    }
    rmdir($path);
}
$routeRoot = sys_get_temp_dir() . '/engram-dev-route-synthetic-' . bin2hex(random_bytes(8));
mkdir($routeRoot, 0700);
$routeOriginal = __DIR__ . '/../../../source/0.9.26-r3/modules/dev';
$routeCandidate = $routeRoot . '/candidate';
$routeWeb = $routeRoot . '/web';
$routePrivate = $routeRoot . '/private';
$routeData = $routePrivate . '/data';
$routeBackups = $routePrivate . '/backups';
mkdir($routeCandidate, 0700);
mkdir($routeWeb, 0755);
mkdir($routePrivate, 0700);
mkdir($routeData, 0700);
mkdir($routeBackups, 0700);
try {
    $generated = KiComEngramDevCandidatePatcher::generate($routeOriginal, $routeCandidate);
    routeCheck(count($generated) === 6 && is_file($routeCandidate . '/DevRouter.php')
        && is_file($routeCandidate . '/DevSession.php')
        && is_file($routeCandidate . '/DevHttpAdapter.php')
        && is_file($routeCandidate . '/DevEndpoint.php')
        && is_file($routeCandidate . '/KiComEngramDevPathHandler.php')
        && is_file($routeCandidate . '/KiComEngramPrivatePathProbe.php'),
        'original-hash-pinned candidate generated without changing trusted R3');
    routeReject(static fn() => KiComEngramDevCandidatePatcher::generate($routeOriginal, $routeCandidate),
        'candidate output is never silently overwritten');
    $tamperedOriginal = $routeRoot . '/tampered-source';
    $tamperedOutput = $routeRoot . '/tampered-output';
    mkdir($tamperedOriginal, 0700);
    mkdir($tamperedOutput, 0700);
    foreach (array_keys($generated) as $name) {
        if (is_file($routeOriginal . '/' . $name)) {
            copy($routeOriginal . '/' . $name, $tamperedOriginal . '/' . $name);
        }
    }
    file_put_contents($tamperedOriginal . '/DevRouter.php', "\n// synthetic tamper", FILE_APPEND);
    routeReject(static fn() => KiComEngramDevCandidatePatcher::generate($tamperedOriginal, $tamperedOutput),
        'tampered trusted source denied');
    routeCheck(scandir($tamperedOutput) === ['.', '..'],
        'rejected source creates no partial candidate');

    require $routeCandidate . '/DevHttpAdapter.php';
    routeCheck(KiComDevSessionManager::capabilityDefined('engram.path.probe')
        && KiComDevRouter::operationCapabilities()['DEV_ENGRAM_PATH_PROBE'] === 'engram.path.probe',
        'dedicated operation bound to explicit dedicated DEV capability');
    routeCheck(!KiComDevSessionManager::capabilityDefined('production.engram.read')
        && !KiComDevSessionManager::capabilityDefined('secrets.engram.read'),
        'production and secrets capabilities still forbidden');
    $sessions = new KiComDevSessionManager($routeRoot . '/sessions');
    $issued = $sessions->issue(['auth_method' => 'passkey', 'credential_id' => 'synthetic-credential']);
    routeCheck(!empty($issued['ok']) && in_array('engram.path.probe', $issued['capabilities'], true),
        'new synthetic DEV session receives only defined capabilities');
    $config = ['data'=>$routeData, 'backups'=>$routeBackups, 'webroot'=>$routeWeb];
    $configCalls = 0;
    $handler = KiComEngramDevPathHandler::handlers(
        static function () use (&$config, &$configCalls): array { $configCalls++; return $config; }
    );
    $router = new KiComDevRouter($sessions, $handler);
    $op = 'DEV_ENGRAM_PATH_PROBE';
    $sid = $issued['session_id'];
    $token = $issued['token'];
    $http = new KiComDevHttpAdapter($router);
    $headers = ['REQUEST_METHOD'=>'POST', 'CONTENT_TYPE'=>'application/json',
        'HTTP_X_KICOM_DEV_SESSION'=>$sid, 'HTTP_X_KICOM_DEV_TOKEN'=>$token];
    $validBody = json_encode(['operation'=>$op, 'payload'=>[]], JSON_THROW_ON_ERROR);
    routeCheck($http->handle(['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json'], $validBody)['http_status'] === 401,
        'HTTP adapter denies missing DEV session headers');
    routeCheck($http->handle(['REQUEST_METHOD'=>'GET'] + $headers, $validBody)['http_status'] === 405,
        'HTTP adapter denies GET');
    $fakeHeaders = $headers;
    $fakeHeaders['HTTP_X_KICOM_DEV_TOKEN'] = str_repeat('0', 64);
    routeCheck($http->handle($fakeHeaders, $validBody)['http_status'] === 401,
        'invalid bearer token denied');
    routeCheck($configCalls === 0, 'unauthenticated calls never inspect trusted filesystem config');
    routeCheck($router->handle('DEV_ENGRAM_UNLISTED', $sid, $token)['code'] === 'DEV_OPERATION_FORBIDDEN',
        'unknown operation cannot route to private probe');
    routeReject(static fn() => $router->register('DEV_SELF_UPDATE_INSTALL', $handler[$op]),
        'protected self-update cannot be registered as DEV handler');
    $legacy = $sessions->issue(['auth_method'=>'passkey','credential_id'=>'synthetic-old']);
    $legacyPath = $routeRoot . '/sessions/sessions/' . $legacy['session_id'] . '.json';
    $legacyState = json_decode((string)file_get_contents($legacyPath), true, 512, JSON_THROW_ON_ERROR);
    $legacyState['capabilities'] = array_values(array_diff($legacyState['capabilities'], ['engram.path.probe']));
    file_put_contents($legacyPath, json_encode($legacyState, JSON_THROW_ON_ERROR));
    routeCheck($router->handle($op, $legacy['session_id'], $legacy['token'])['code'] === 'DEV_CAPABILITY_FORBIDDEN',
        'preexisting session without explicit probe grant denied');
    routeCheck($configCalls === 0, 'missing capability cannot resolve private paths');
    $forged = $router->handle($op, $sid, $token, ['data'=>$routeWeb,'backups'=>$routeWeb]);
    routeCheck($forged['code'] === 'ENGRAM_PATH_PROBE_PAYLOAD_FORBIDDEN',
        'request-supplied paths and extra payload rejected');
    routeCheck($configCalls === 0, 'rejected payload never reads server filesystem configuration');
    $good = $http->handle($headers, $validBody);
    routeCheck($good['http_status'] === 200
        && ($good['body']['code'] ?? '') === 'DEV_ENGRAM_PATH_PROBE_OK'
        && ($good['body']['synthetic_rw_data'] ?? false)
        && ($good['body']['synthetic_rw_backups'] ?? false)
        && ($good['body']['public_http_exposure_verified'] ?? true) === false,
        'authorized DEV-only route performs bounded local synthetic read/write');
    routeCheck(scandir($routeData) === ['.', '..'] && scandir($routeBackups) === ['.', '..'],
        'authorized probe leaves no residual synthetic private files');
    routeCheck(!str_contains(json_encode($good, JSON_THROW_ON_ERROR), $routeRoot),
        'DEV probe response does not disclose local filesystem paths');
    $config = ['data'=>$routeData,'backups'=>$routeBackups,'webroot'=>$routeData];
    $blocked = $router->handle($op, $sid, $token);
    routeCheck($blocked['code'] === 'ENGRAM_PATH_PROBE_UNAVAILABLE'
        && !str_contains(json_encode($blocked, JSON_THROW_ON_ERROR), $routeRoot),
        'invalid trusted server mapping fails closed without disclosing paths');
    $config = [];
    routeCheck($router->handle($op, $sid, $token)['code'] === 'ENGRAM_PATH_PROBE_UNAVAILABLE',
        'unprovisioned server configuration fails closed');
    $config = ['data'=>$routeData,'backups'=>$routeBackups,'webroot'=>$routeWeb];
    $sessions->revoke($sid);
    routeCheck($router->handle($op, $sid, $token)['code'] === 'DEV_SESSION_REVOKED',
        'revoked DEV session can no longer access private probe');
    echo "KICOM_ENGRAM_DEV_ROUTE_TESTS_PASSED=$routeChecks\n";
    require __DIR__ . '/test-engram-endpoint.php';
} finally {
    routeClean($routeRoot);
}
