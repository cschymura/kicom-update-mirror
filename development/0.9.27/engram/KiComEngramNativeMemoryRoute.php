<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramDevMemoryAdapter.php';
require_once __DIR__.'/KiComEngramDevSessionCredentialReader.php';
require_once __DIR__.'/KiComEngramVerifiedCredentialResolver.php';
require_once __DIR__.'/KiComEngramPrivateOwnerRegistry.php';
require_once __DIR__.'/KiComEngramPrivateConsentLedger.php';
require_once __DIR__.'/KiComEngramPrivatePathProbe.php';

/**
 * Native KiCom api.php memory route, DISABLED without a separately trusted
 * host runtime configuration and proven operator-reviewed isolation. A DEV
 * bearer authenticates only the transport: owner/rights come exclusively from
 * verified original KiCom session credential + private owner registry.
 *
 * This class NEVER creates a consent approval; only consumes an already
 * independently WebAuthn-reviewed exact-record approval via the private ledger.
 * It does NOT activate first-party browser review or enable ChatGPT connectors.
 */
final class KiComEngramNativeMemoryRoute
{
    public static function handle(array $server,string $raw,array $trusted): array
    {
        $headers=[
            'Cache-Control'=>'no-store, private','Pragma'=>'no-cache',
            'Content-Type'=>'application/json; charset=utf-8',
            'X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'DENY',
        ];
        $reply=static fn(int $status,string $code):array=>[
            'http_status'=>$status,'headers'=>$headers,
            'body'=>['ok'=>false,'code'=>$code],
        ];
        // Fail closed before constructing even an identity or private store:
        // a request, Slack text or a DEV bearer can never set these flags.
        if (($trusted['enabled']??null)!==true
            || ($trusted['operator_approved']??null)!==true
            || ($trusted['host_isolation_verified']??null)!==true
            || ($trusted['runtime_source']??null)!=='server-only-reviewed'
            || ($trusted['private_memory_scope']??null)!=='dev-verified-owner') {
            return $reply(404,'ENGRAM_MEMORY_UNAVAILABLE');
        }
        if (($server['HTTPS']??'')!=='on'
            || ($server['REQUEST_METHOD']??'')!=='POST') {
            return $reply(403,'ENGRAM_MEMORY_TRANSPORT_DENIED');
        }
        if (strlen($raw)>16384) {
            return $reply(413,'ENGRAM_MEMORY_BODY_TOO_LARGE');
        }
        try {
            $web=$trusted['web_root']??null;
            $data=$trusted['data_dir']??null;
            $backups=$trusted['backups_dir']??null;
            $roots=$trusted['reviewed_web_roots']??null;
            $sessionRoot=$trusted['dev_session_root']??null;
            $registryPath=$trusted['owner_registry']??null;
            $consentDir=$trusted['consent_dir']??null;
            if (!is_string($web)||!is_string($data)||!is_string($backups)
                || !is_string($sessionRoot)||!is_string($registryPath)
                || !is_string($consentDir)||!is_array($roots)
                || !in_array($web,$roots,true)) {
                return $reply(404,'ENGRAM_MEMORY_UNAVAILABLE');
            }
            // Every vhost root must be in this SERVER-OWNED reviewed inventory;
            // probe alone cannot attest unlisted aliases, PHP UIDs or HTTP.
            $proof=KiComEngramPrivatePathProbe::runAgainstWebRoots(
                $data,$backups,$roots
            );
            if (($proof['private_paths_checked']??null)!==true
                || ($proof['configured_webroots_checked']??null)!==count($roots)) {
                return $reply(404,'ENGRAM_MEMORY_UNAVAILABLE');
            }
            $sessions=new KiComDevSessionManager($sessionRoot);
            $record=new KiComEngramDevSessionCredentialReader($sessionRoot);
            $owners=new KiComEngramPrivateOwnerRegistry($registryPath,$web);
            $identity=new KiComEngramVerifiedCredentialResolver($record,$owners);
            // Ledger cannot ISSUE approval through this route. A separate
            // first-party reviewed WebAuthn confirmation must have done so.
            $consent=new KiComEngramPrivateConsentLedger(
                $consentDir,$web,static fn(array $binding):bool=>false
            );
            $adapter=new KiComEngramDevMemoryAdapter(
                $sessions,$identity,[$consent,'consume'],
                static fn():KiComEngramStore=>new KiComEngramStore($data,$web)
            );
            return $adapter->handle($server,$raw);
        } catch(Throwable $e) {
            // Never reflect filesystem paths, identities or private contents.
            return $reply(404,'ENGRAM_MEMORY_UNAVAILABLE');
        }
    }
}
