<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramOAuthHttp.php';
require_once __DIR__.'/KiComEngramOAuthPasskeyConsent.php';

/**
 * DEV-55: authenticated FIRST-PARTY KiCom admin OAuth authorization endpoint.
 *
 * To be called ONLY from the original admin.php after its guardian, original
 * admin session, CSRF and kicomEngramServerRuntime() have been initialized.
 * All supplied dependencies MUST be constructed from original KiCom
 * server-owned private files. No client-supplied redirect or runtime authority.
 * This class performs no login, no passkey enrollment, no HTTP route creation
 * and no host activation. Requires an operator-pinned real ChatGPT client.
 */
final class KiComEngramOAuthAuthorizeHttp
{
    private const BASE_HEADERS = [
        'Cache-Control'=>'no-store, private',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'Content-Security-Policy'=>"default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'",
    ];

    public static function handle(
        array $server, array $query, string $rawBody, array &$originalAdminSession,
        string $originalSessionId, string $originalCsrf,
        array $trustedRuntime, array $pinnedClient,
        PDO $privateOAuthDb, KiComPasskeyBridge $originalPasskeys,
        KiComEngramPrivateOwnerRegistry $currentOwners, int $now
    ): array {
        if (!KiComEngramOAuthHttp::available($trustedRuntime)
            || ($server['HTTPS']??null)!=='on'
            || ($server['HTTP_HOST']??null)!=='kicom.rurtalbahn.info'
            || ($originalAdminSession['admin']??null)!==true
            || !is_string($originalAdminSession['csrf']??null)
            || !hash_equals($originalAdminSession['csrf'],$originalCsrf)
            || strlen($originalSessionId)<24) return self::empty404();

        $method=$server['REQUEST_METHOD']??'';
        if ($method==='GET') {
            // Only the originally authenticated admin session may begin an
            // OAuth request. Client id, callback, scope and resource must be
            // separately pinned to operator-owned server configuration.
            try {
                if (array_keys($query)===['engram_oauth','request_id']
                    && $query['engram_oauth']==='1'
                    && is_string($query['request_id'])) {
                    $review=KiComEngramOAuthPasskeyConsent::review(
                        $privateOAuthDb,$query['request_id'],$originalAdminSession,
                        $originalSessionId,$originalCsrf,$originalCsrf,true,$now
                    );
                    $id=$query['request_id'];
                } else {
                    $p=$query;
                    if (($p['engram_oauth']??null)!=='1')return self::empty404();
                    unset($p['engram_oauth']);
                    $created=KiComEngramOAuthTransactions::begin(
                        $privateOAuthDb,$p,$pinnedClient,$originalSessionId,$now
                    );
                    $id=$created['request_id'];
                    $review=KiComEngramOAuthPasskeyConsent::review(
                        $privateOAuthDb,$id,$originalAdminSession,
                        $originalSessionId,$originalCsrf,$originalCsrf,true,$now
                    );
                }
                return self::html(200,self::page($id,$review,$originalCsrf));
            } catch (Throwable) {
                return self::empty404();
            }
        }

        if ($method!=='POST' || ($server['CONTENT_TYPE']??null)!=='application/json'
            || strlen($rawBody)>16384 || $rawBody===''
            || (isset($server['HTTP_ORIGIN'])
                && $server['HTTP_ORIGIN']!=='https://kicom.rurtalbahn.info'))
            return self::empty404();

        try {
            $p=json_decode($rawBody,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($p)||array_is_list($p))return self::denied();
            $step=$p['step']??null;
            $csrf=$p['csrf']??null;
            $requestId=$p['request_id']??null;
            if (!is_string($csrf)||!is_string($requestId)
                || !hash_equals($originalCsrf,$csrf))return self::denied();

            if ($step==='challenge' && self::keys($p,['step','csrf','request_id'])) {
                $started=KiComEngramOAuthPasskeyConsent::begin(
                    $privateOAuthDb,$requestId,$originalAdminSession,
                    $originalSessionId,$originalCsrf,$csrf,true,
                    $originalPasskeys,$now
                );
                return self::json(200,[
                    'ok'=>true,
                    'challenge_id'=>$started['challenge_id'],
                    'publicKey'=>$started['publicKey'],
                ]);
            }
            if ($step==='confirm' && self::keys($p,[
                'step','csrf','request_id','challenge_id','assertion','consent'
            ]) && $p['consent']===true && is_string($p['challenge_id'])
                && is_array($p['assertion']) && !array_is_list($p['assertion'])) {
                $issued=KiComEngramOAuthPasskeyConsent::confirm(
                    $privateOAuthDb,$requestId,$originalAdminSession,
                    $originalSessionId,$originalCsrf,$csrf,true,
                    $p['challenge_id'],$p['assertion'],true,
                    $originalPasskeys,$currentOwners,$now
                );
                // Never trust a redirect location from the client or from
                // stale pending data; compare against CURRENT trusted pin.
                if (!is_string($issued['redirect_uri']??null)
                    || !hash_equals($pinnedClient['redirect_uri'],$issued['redirect_uri'])
                    || !is_string($issued['code']??null)
                    || !is_string($issued['state']??null))
                    return self::denied();
                $redirect=$pinnedClient['redirect_uri']
                    .'?'.http_build_query([
                        'code'=>$issued['code'],'state'=>$issued['state']
                    ],'','&',PHP_QUERY_RFC3986);
                return self::json(200,[
                    'ok'=>true,'redirect_to'=>$redirect
                ]);
            }
            return self::denied();
        } catch (Throwable) {
            return self::denied();
        }
    }

    private static function page(string $id,array $review,string $csrf):string
    {
        $h=static fn(string $s):string=>htmlspecialchars(
            $s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'
        );
        // The user must explicitly click "Allow read" BEFORE requesting a
        // fresh signed passkey assertion. The review is NOT implicit consent.
        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Mirage Engram – ChatGPT verbinden</title>'
            .'<link rel="stylesheet" href="assets/control-plane.css"></head>'
            .'<body><main id="mirage-oauth-consent" data-request-id="'.$h($id)
            .'" data-csrf="'.$h($csrf).'"><h1>ChatGPT mit Mirage verbinden</h1>'
            .'<p>Du erlaubst diesem Client ausschließlich den lesenden Abruf '
            .'bereits freigegebener Projekt-Erinnerungen. Kein automatisches Speichern.</p>'
            .'<dl><dt>Client</dt><dd>'.$h((string)$review['client_id']).'</dd>'
            .'<dt>Rücksprung</dt><dd>'.$h((string)$review['redirect_uri']).'</dd>'
            .'<dt>Berechtigung</dt><dd>'.$h((string)$review['scope']).'</dd></dl>'
            .'<label><input id="mirage-oauth-consent-check" type="checkbox">'
            .' Ich erlaube den lesenden Zugriff für diesen Client.</label>'
            .'<p><button type="button" id="mirage-oauth-consent-button" disabled>'
            .'Mit KiCom-Passkey freigeben</button></p>'
            .'<p id="mirage-oauth-consent-status" role="status"></p>'
            .'<p><a href="admin.php">Abbrechen</a></p></main>'
            .'<script src="assets/mirage-oauth-client.js" defer></script></body></html>';
    }

    private static function keys(array $payload,array $exact):bool
    {
        $a=array_keys($payload);sort($a,SORT_STRING);
        sort($exact,SORT_STRING);return $a===$exact;
    }

    private static function json(int $status,array $body):array
    {
        return ['http_status'=>$status,
            'headers'=>self::BASE_HEADERS+['Content-Type'=>'application/json; charset=utf-8'],
            'body'=>json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
    }
    private static function html(int $status,string $body):array
    {
        return ['http_status'=>$status,
            'headers'=>self::BASE_HEADERS+['Content-Type'=>'text/html; charset=utf-8'],
            'body'=>$body];
    }
    private static function denied():array
    {
        return self::json(403,['ok'=>false,'error'=>'OAUTH_CONSENT_DENIED']);
    }
    private static function empty404():array
    {
        return ['http_status'=>404,'headers'=>self::BASE_HEADERS,'body'=>''];
    }
}
