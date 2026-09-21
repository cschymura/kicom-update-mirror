<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramMcpProtocol.php';
require_once __DIR__.'/KiComEngramMcpBearerVerifier.php';

/**
 * DEV-47: first-party remote MCP HTTPS request handler.
 * A production web route is intentionally NOT installed here. The eventual
 * KiCom router must supply trusted root, token record path, private registry,
 * activation DB and host policy (never from request headers/JSON).
 */
final class KiComEngramMcpHttpTransport
{
    private const HEADERS=[
        'Cache-Control'=>'no-store, private',
        'X-Content-Type-Options'=>'nosniff',
        'Vary'=>'Accept, Authorization, MCP-Protocol-Version',
    ];

    public static function handle(
        array $server,
        string $raw,
        string $trustedWebRoot,
        string $trustedTokenRecord,
        array $trustedRuntime,
        PDO $activationDb,
        callable $lookupOwner,
        int $now,
        callable $adapterFactory
    ): array {
        $quiet=static fn(int $status):array=>[
            'http_status'=>$status,'headers'=>self::HEADERS,'body'=>'',
        ];
        // Reject before touching ANY token file or private SQLite. HTTP host
        // is not used for trusted configuration or subject resolution.
        if (($server['HTTPS']??null)!=='on'
            || ($server['REQUEST_METHOD']??null)!=='POST'
            || ($server['HTTP_HOST']??null)!=='kicom.rurtalbahn.info'
            || ($server['CONTENT_TYPE']??null)!=='application/json'
            || !is_string($server['HTTP_ACCEPT']??null)
            || !str_contains($server['HTTP_ACCEPT'],'application/json')
            || !str_contains($server['HTTP_ACCEPT'],'text/event-stream')
            || (isset($server['HTTP_ORIGIN'])
                && $server['HTTP_ORIGIN']!=='https://kicom.rurtalbahn.info')
            || (isset($server['HTTP_MCP_PROTOCOL_VERSION'])
                && $server['HTTP_MCP_PROTOCOL_VERSION']!=='2025-06-18')
            || strlen($raw)>8192) {
            return $quiet(404);
        }
        $auth=$server['HTTP_AUTHORIZATION']??null;
        if (!is_string($auth)) return $quiet(404);
        // The ONLY way to derive verified connector identity is a freshly
        // checked high-entropy bearer credential from an out-of-webroot file.
        $identity=KiComEngramMcpBearerVerifier::verify(
            $trustedTokenRecord,$trustedWebRoot,$auth,$now
        );
        if ($identity===null) return $quiet(404);
        $response=KiComEngramMcpProtocol::handle(
            $raw,$trustedRuntime,$activationDb,$lookupOwner,
            $identity,$now,$adapterFactory
        );
        $code=$response['http_status']??404;
        $body=$response['body']??'';
        if (!is_int($code)||!is_string($body)||strlen($body)>16384
            || !in_array($code,[200,202,404],true)) return $quiet(404);
        $headers=self::HEADERS;
        if ($code===200) {
            $headers['Content-Type']='application/json; charset=utf-8';
            $headers['MCP-Protocol-Version']='2025-06-18';
        }
        return ['http_status'=>$code,'headers'=>$headers,'body'=>$body];
    }
}
