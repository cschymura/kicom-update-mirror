<?php
declare(strict_types=1);
// Included from the disposable, synthetic DEV route suite, before its cleanup.
$endpointChecks = 0;
function endpointCheck(bool $okay, string $label): void
{
    global $endpointChecks;
    if (!$okay) { throw new RuntimeException('FAIL ' . $label); }
    $endpointChecks++;
    echo 'PASS ' . $label . "\n";
}
final class KiComDevRuntimeBindings
{
    public static function handlers(): array { return []; }
}
final class KiComDevDiagnostics
{
    public static function handlers(): array { return []; }
}
$GLOBALS['engram_route_root'] = $routeRoot;
function kicomVarDir(): string { return $GLOBALS['engram_route_root'] . '/var'; }
require $routeCandidate . '/DevEndpoint.php';

$operation = 'DEV_ENGRAM_PATH_PROBE';
$emptyPayload = json_encode(['operation'=>$operation,'payload'=>[]], JSON_THROW_ON_ERROR);
$withoutConfig = kicomDevRouter();
$unconfiguredCreds = kicomDevSessions()->issue(['auth_method'=>'passkey','credential_id'=>'synthetic-unconfigured']);
$missing = $withoutConfig->handle($operation,$unconfiguredCreds['session_id'],$unconfiguredCreds['token']);
endpointCheck(($missing['code'] ?? '') === 'DEV_OPERATION_NOT_IMPLEMENTED',
    'real DEV endpoint leaves private probe unregistered without trusted config');

$GLOBALS['engram_fixture_config'] = ['data'=>$routeData,'backups'=>$routeBackups,'webroot'=>$routeWeb];
$GLOBALS['engram_fixture_config_reads'] = 0;
require __DIR__ . '/fixture-engram-trusted-config.php';
$apiCreds = kicomDevSessions()->issue(['auth_method'=>'passkey','credential_id'=>'synthetic-endpoint']);
$apiSession = $apiCreds['session_id'];
$apiToken = $apiCreds['token'];
$apiHeaders = ['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json',
    'HTTP_X_KICOM_DEV_SESSION'=>$apiSession,'HTTP_X_KICOM_DEV_TOKEN'=>$apiToken];
$noCredentials = kicomDevApiDispatch(
    ['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json'], $emptyPayload);
endpointCheck($noCredentials['http_status'] === 401
    && $GLOBALS['engram_fixture_config_reads'] === 0,
    'actual endpoint rejects missing session headers before reading private config');
$invalidHeaders = $apiHeaders;
$invalidHeaders['HTTP_X_KICOM_DEV_TOKEN'] = str_repeat('0',64);
$invalid = kicomDevApiDispatch($invalidHeaders,$emptyPayload);
endpointCheck($invalid['http_status'] === 401
    && $GLOBALS['engram_fixture_config_reads'] === 0,
    'actual endpoint rejects invalid token without private config read');
$deniedQuery = kicomDevBridgeDispatch(
    ['op'=>$operation,'sid'=>$apiSession,'key'=>$apiToken]);
endpointCheck($deniedQuery['code'] === 'DEV_ENGRAM_POST_REQUIRED'
    && $GLOBALS['engram_fixture_config_reads'] === 0,
    'actual GET bridge cannot invoke private probe or resolve private config');
$injected = kicomDevApiDispatch($apiHeaders,json_encode([
    'operation'=>$operation,
    'payload'=>['data'=>$routeWeb,'backups'=>$routeWeb,'webroot'=>$routeWeb],
], JSON_THROW_ON_ERROR));
endpointCheck(($injected['body']['code'] ?? '') === 'ENGRAM_PATH_PROBE_PAYLOAD_FORBIDDEN'
    && $GLOBALS['engram_fixture_config_reads'] === 0,
    'actual endpoint rejects request path injection before private config read');
$worked = kicomDevApiDispatch($apiHeaders,$emptyPayload);
endpointCheck($worked['http_status'] === 200
    && ($worked['body']['code'] ?? '') === 'DEV_ENGRAM_PATH_PROBE_OK'
    && ($worked['body']['private_paths_checked'] ?? false)
    && ($worked['body']['synthetic_rw_data'] ?? false)
    && ($worked['body']['synthetic_rw_backups'] ?? false)
    && ($worked['body']['public_http_exposure_verified'] ?? true) === false
    && $GLOBALS['engram_fixture_config_reads'] === 1,
    'actual first-party DEV endpoint executes only authorized fixed-config synthetic probe');
endpointCheck(scandir($routeData) === ['.','..'] && scandir($routeBackups) === ['.','..'],
    'first-party endpoint leaves no synthetic private file behind');
endpointCheck(!str_contains(json_encode($worked, JSON_THROW_ON_ERROR), $routeRoot),
    'first-party endpoint response exposes no absolute filesystem path');
$GLOBALS['engram_fixture_config'] = ['data'=>$routeWeb,'backups'=>$routeBackups,'webroot'=>$routeWeb];
$mappingDenied = kicomDevApiDispatch($apiHeaders,$emptyPayload);
endpointCheck(($mappingDenied['body']['code'] ?? '') === 'ENGRAM_PATH_PROBE_UNAVAILABLE'
    && !str_contains(json_encode($mappingDenied, JSON_THROW_ON_ERROR), $routeRoot),
    'wrong trusted configuration fails closed without path disclosure');
$GLOBALS['engram_fixture_config'] = ['data'=>$routeData,'backups'=>$routeBackups,'webroot'=>$routeWeb];
$missingModule = $routeCandidate . '/KiComEngramPrivatePathProbe.php';
$hiddenModule = $missingModule . '.disabled';
if (!rename($missingModule,$hiddenModule)) { throw new RuntimeException('Cannot simulate missing module'); }
try {
    $notRegistered = kicomDevRouter()->handle($operation,$apiSession,$apiToken);
    endpointCheck(($notRegistered['code'] ?? '') === 'DEV_OPERATION_NOT_IMPLEMENTED',
        'actual endpoint does not register probe if candidate module is missing');
} finally {
    rename($hiddenModule,$missingModule);
}
kicomDevSessions()->revoke($apiSession);
$revokedResult = kicomDevApiDispatch($apiHeaders,$emptyPayload);
endpointCheck($revokedResult['http_status'] === 401
    && $GLOBALS['engram_fixture_config_reads'] === 2,
    'actual endpoint rejects revoked session without another private config read');
echo "KICOM_ENGRAM_ENDPOINT_TESTS_PASSED=$endpointChecks\n";
