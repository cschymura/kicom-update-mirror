<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramSignedOwnerActivation.php';

/**
 * DEV-64: authenticated original-KiCom-admin consent page for ACTUAL
 * separately approved OAuth/MCP READ activation. No anonymous setup routes.
 * This does not enroll passkeys, issue OAuth access tokens, create DBs or
 * accept trust decisions, server paths or identity fields from HTTP JSON.
 */
final class KiComEngramOwnerActivationHttp
{
    private const HEADERS=[
        'Cache-Control'=>'no-store, private',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'Content-Security-Policy'=>"default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'"
    ];

    public static function handle(
        array $server,string $raw,
        array &$session,string $sessionId,string $csrf,
        string $trustedWebRoot,KiComPasskeyBridge $originalPasskeys,int $now
    ):array {
        if(($server['HTTPS']??null)!=='on'
            || ($server['HTTP_HOST']??null)!=='kicom.rurtalbahn.info'
            || ($session['admin']??null)!==true
            || !is_string($session['csrf']??null)
            || !hash_equals($session['csrf'],$csrf)
            || strlen($csrf)<24 || strlen($sessionId)<24
            || (isset($server['HTTP_ORIGIN'])
                && $server['HTTP_ORIGIN']!=='https://kicom.rurtalbahn.info'))
            return self::denied(404);
        if(($server['REQUEST_METHOD']??null)==='GET'){
            if($raw!=='')return self::denied(404);
            return self::respond(200,'text/html; charset=utf-8',self::page($csrf));
        }
        if(($server['REQUEST_METHOD']??null)!=='POST'
            || ($server['CONTENT_TYPE']??null)!=='application/json'
            || $raw===''||strlen($raw)>16384)return self::denied(404);
        try{
            $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_is_list($p)
                || !is_string($p['csrf']??null)
                || !hash_equals($csrf,$p['csrf']))return self::denied(403);
            if(($p['step']??null)==='begin' && self::keys($p,['step','csrf'])){
                $started=KiComEngramSignedOwnerActivation::begin(
                    $trustedWebRoot,$session,$sessionId,$csrf,$p['csrf'],
                    true,$originalPasskeys,$now
                );
                return self::respond(200,'application/json; charset=utf-8',
                    json_encode(['ok'=>true]+$started,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            }
            if(($p['step']??null)==='confirm'
                &&self::keys($p,['step','csrf','challenge_id','confirmation','assertion'])
                &&is_string($p['challenge_id'])
                &&is_string($p['confirmation'])
                &&is_array($p['assertion'])&&!array_is_list($p['assertion'])){
                $result=KiComEngramSignedOwnerActivation::confirm(
                    $trustedWebRoot,$session,$sessionId,$csrf,$p['csrf'],true,
                    $p['confirmation'],$p['challenge_id'],$p['assertion'],
                    $originalPasskeys,$now
                );
                return self::respond(200,'application/json; charset=utf-8',
                    json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            }
            return self::denied(403);
        }catch(Throwable){return self::denied(403);}
    }

    private static function page(string $csrf):string
    {
        $h=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Mirage Engram – Zugriff aktivieren</title>'
            .'<link rel="stylesheet" href="assets/control-plane.css"></head>'
            .'<body><main id="mirage-activation" data-csrf="'.$h.'">'
            .'<h1>Privaten Engram-Lesezugriff für ChatGPT aktivieren</h1>'
            .'<p>Dies ist eine neue, eigenständige Freigabe. Die zuvor akzeptierte '
            .'Hosting-Konfiguration allein hat den Engram-Speicher nicht aktiviert.</p>'
            .'<p>Nach deiner Passkey-Bestätigung können verbundene und einzeln '
            .'autorisierte ChatGPT-Clients die freigegebenen Projekt-Erinnerungen '
            .'lesen. Eine automatische Übernahme von Chat-Inhalten erfolgt nicht.</p>'
            .'<label><input type="checkbox" id="mirage-activation-accept">'
            .' Ich möchte jetzt den privaten Engram-Lesezugriff und OAuth aktivieren.</label>'
            .'<p><button id="mirage-activation-button" type="button" disabled>'
            .'Engram-Zugriff mit KiCom-Passkey aktivieren</button></p>'
            .'<p id="mirage-activation-status" role="status"></p>'
            .'<p><a href="admin.php">Zurück zur KiCom-Administration</a></p>'
            .'</main><script src="assets/mirage-owner-activation-client.js" defer></script>'
            .'</body></html>';
    }

    private static function keys(array $data,array $expected):bool
    {
        $a=array_keys($data);sort($a,SORT_STRING);
        sort($expected,SORT_STRING);return $a===$expected;
    }
    private static function respond(int $status,string $type,string $body):array
    {
        return ['http_status'=>$status,
            'headers'=>self::HEADERS+['Content-Type'=>$type],
            'body'=>$body];
    }
    private static function denied(int $status):array
    {
        return self::respond($status,'application/json; charset=utf-8',
            $status===403?'{"ok":false,"error":"ACTIVATION_DENIED"}':'');
    }
}
