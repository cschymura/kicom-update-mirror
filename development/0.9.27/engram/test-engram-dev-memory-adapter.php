<?php
declare(strict_types=1);
if (!class_exists('KiComDevSessionManager', false)) {
    require_once __DIR__ . '/../../../source/0.9.26-r3/modules/dev/DevSession.php';
}
require_once __DIR__ . '/KiComEngramDevMemoryAdapter.php';

$httpChecks = 0;
$check = static function (bool $condition, string $label) use (&$httpChecks): void {
    if (!$condition) { throw new RuntimeException('FAIL adapter: ' . $label); }
    $httpChecks++;
    echo "PASS adapter " . $label . "\n";
};
$clean = static function (string $path) use (&$clean): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') $clean($path . '/' . $name);
    }
    rmdir($path);
};
$root = sys_get_temp_dir() . '/engram-dev-memory-http-' . bin2hex(random_bytes(7));
mkdir($root, 0700);
mkdir($root . '/web', 0755);
mkdir($root . '/private', 0700);
try {
    $sessions = new KiComDevSessionManager($root . '/sessions');
    $a = $sessions->issue(['auth_method' => 'passkey', 'credential_id' => 'synthetic-a']);
    $b = $sessions->issue(['auth_method' => 'passkey', 'credential_id' => 'synthetic-b']);
    $unbound = $sessions->issue(['auth_method' => 'passkey', 'credential_id' => 'synthetic-unbound']);
    $check(!empty($a['ok']) && !empty($b['ok']), 'two independent synthetic DEV sessions issued');
    $actors = [
        $a['session_id'] => [
            'verified' => true, 'subject' => 'synthetic-a', 'namespaces' => ['project'],
            'engram_rights' => ['engram.read', 'engram.write']
        ],
        $b['session_id'] => [
            'verified' => true, 'subject' => 'synthetic-b', 'namespaces' => ['project'],
            'engram_rights' => ['engram.read', 'engram.write']
        ],
    ];
    $identityCalls = 0;
    $identityForSession = static function (array $auth) use (&$actors, &$identityCalls): array {
        $identityCalls++;
        return $actors[$auth['session_id']] ?? ['verified' => false];
    };
    $entry = [
        'namespace' => 'project', 'kind' => 'technical',
        'body' => 'Synthetic blue train memory available after independent session restart.',
        'source_kind' => 'approved_summary',
        'source_ref' => 'summary:synthetic-http-adapter-01',
        'sensitivity' => 'ordinary',
    ];
    $approvals = 0;
    $consent = static function (array $binding) use (&$approvals, $entry): bool {
        if ($approvals !== 0
            || ($binding['subject'] ?? '') !== 'synthetic-a'
            || ($binding['namespace'] ?? '') !== 'project'
            || ($binding['body_sha256'] ?? '') !== hash('sha256', $entry['body'])
            || ($binding['source_ref_sha256'] ?? '') !== hash('sha256', $entry['source_ref'])
        ) return false;
        $approvals++;
        return true;
    };
    $storeOpens = 0;
    $openStore = static function () use ($root, &$storeOpens): KiComEngramStore {
        $storeOpens++;
        return new KiComEngramStore($root . '/private', $root . '/web');
    };
    $newAdapter = static fn() => new KiComEngramDevMemoryAdapter(
        $sessions, $identityForSession, $consent, $openStore
    );
    $adapter = $newAdapter();
    $headersA = [
        'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json',
        'HTTP_X_KICOM_DEV_SESSION' => $a['session_id'],
        'HTTP_X_KICOM_DEV_TOKEN' => $a['token'],
    ];
    $headersB = $headersA;
    $headersB['HTTP_X_KICOM_DEV_SESSION'] = $b['session_id'];
    $headersB['HTTP_X_KICOM_DEV_TOKEN'] = $b['token'];
    $remember = json_encode(['operation' => 'ENGRAM_REMEMBER', 'payload' => $entry], JSON_THROW_ON_ERROR);
    $query = ['namespace' => 'project', 'query' => 'blue train', 'limit' => 5];
    $recall = json_encode(['operation' => 'ENGRAM_RECALL', 'payload' => $query], JSON_THROW_ON_ERROR);

    $check($adapter->handle(['REQUEST_METHOD' => 'GET'] + $headersA, $remember)['http_status'] === 405,
        'GET cannot modify or retrieve memories');
    $check($adapter->handle(['CONTENT_TYPE' => 'text/plain'] + $headersA, $remember)['http_status'] === 415,
        'unsupported content type rejected');
    $check($adapter->handle([], $recall)['http_status'] === 405, 'missing POST denied');
    $noCredentials = $headersA;
    unset($noCredentials['HTTP_X_KICOM_DEV_TOKEN']);
    $check($adapter->handle($noCredentials, $remember)['http_status'] === 401,
        'missing credential denied');
    $invalid = $headersA;
    $invalid['HTTP_X_KICOM_DEV_TOKEN'] = str_repeat('0', 64);
    $check($adapter->handle($invalid, $recall)['http_status'] === 401,
        'invalid token cannot retrieve memory');
    $check($identityCalls === 0 && $storeOpens === 0 && $approvals === 0,
        'unauthenticated requests never resolve identity, storage or consent');
    $unboundHeaders = $headersA;
    $unboundHeaders['HTTP_X_KICOM_DEV_SESSION'] = $unbound['session_id'];
    $unboundHeaders['HTTP_X_KICOM_DEV_TOKEN'] = $unbound['token'];
    $check($adapter->handle($unboundHeaders, $recall)['http_status'] === 403
        && $storeOpens === 0, 'valid DEV session without trusted subject binding denied before storage');
    $check($adapter->handle($headersA, $remember)['body']['code'] === 'ENGRAM_REMEMBER_OK'
        && $approvals === 1, 'authenticated, consent-bound synthetic memory persisted');
    $read = $adapter->handle($headersA, $recall);
    $check($read['http_status'] === 200
        && ($read['body']['count'] ?? null) === 1
        && $read['body']['records'][0]['body'] === $entry['body']
        && $read['headers']['Cache-Control'] === 'no-store, private',
        'bounded scoped private read with no-store response headers');

    $independentA = $sessions->issue(['auth_method' => 'passkey', 'credential_id' => 'synthetic-a']);
    $actors[$independentA['session_id']] = $actors[$a['session_id']];
    $newHeaders = $headersA;
    $newHeaders['HTTP_X_KICOM_DEV_SESSION'] = $independentA['session_id'];
    $newHeaders['HTTP_X_KICOM_DEV_TOKEN'] = $independentA['token'];
    $fresh = $newAdapter()->handle($newHeaders, $recall);
    $check($fresh['http_status'] === 200
        && $fresh['body']['records'][0]['id'] === $read['body']['records'][0]['id'],
        'independently issued new session with verified same subject recovers stored memory');
    $check($newAdapter()->handle($headersB, $recall)['body']['count'] === 0,
        'different authenticated subject never sees another subject records');
    $injected = json_encode(['operation'=>'ENGRAM_RECALL','payload'=>$query + ['subject'=>'synthetic-a']], JSON_THROW_ON_ERROR);
    $denied = $adapter->handle($headersB, $injected);
    $check($denied['http_status'] === 403 && !str_contains(json_encode($denied), $entry['body']),
        'client supplied subject field cannot impersonate another owner');
    $check($adapter->handle($headersA, $remember)['http_status'] === 403 && $approvals === 1,
        'consent replay cannot duplicate engram');
    $forged = json_encode(['operation'=>'ENGRAM_REMEMBER','payload'=>$entry + ['approved'=>true]], JSON_THROW_ON_ERROR);
    $check($adapter->handle($headersA, $forged)['http_status'] === 403,
        'client supplied approval flag rejected');
    $replay = $headersA;
    $sessions->revoke($a['session_id']);
    $before = $storeOpens;
    $check($adapter->handle($replay, $recall)['http_status'] === 401
        && $storeOpens === $before,
        'revoked session fails before touching private store');
    $fake = json_encode(['operation'=>'ENGRAM_RECALL','payload'=>$query,'subject'=>'synthetic-a'], JSON_THROW_ON_ERROR);
    $check($adapter->handle($newHeaders, $fake)['http_status'] === 400,
        'unrecognized outer request identity rejected');
    $check($adapter->handle($newHeaders, str_repeat('x', 16385))['http_status'] === 413,
        'oversized request rejected');
    $check($adapter->handle($newHeaders, '{}')['http_status'] === 400,
        'missing operation rejected');
    $check($adapter->handle($newHeaders, json_encode(['operation'=>'ENGRAM_INSTALL','payload'=>['ignored'=>'synthetic']], JSON_THROW_ON_ERROR))['http_status'] === 403,
        'forbidden installation operation cannot route through memory bridge');
    $check($newAdapter()->handle($newHeaders, $recall)['body']['count'] === 1,
        'negative requests leave original synthetic memory available');
    echo "KICOM_ENGRAM_DEV_MEMORY_ADAPTER_TESTS_PASSED=$httpChecks\n";
} finally {
    unset($adapter, $sessions);
    $clean($root);
}
