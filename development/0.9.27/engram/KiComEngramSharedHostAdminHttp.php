<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramSharedHostAdminConfig.php';

/**
 * DEV-63: authenticated FIRST-PARTY KiCom admin page/JSON handler for a
 * signed, INACTIVE private host policy. Not accessible from api.php/MCP.
 * Original admin.php must supply ORIGINAL $_SESSION/session_id()/csrf()
 * and original KiComPasskeyBridge, never values from the HTTP request.
 */
final class KiComEngramSharedHostAdminHttp
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
            || strlen($sessionId)<24 || strlen($csrf)<24
            || !is_string($session['csrf']??null)
            || !hash_equals($session['csrf'],$csrf)
            || (isset($server['HTTP_ORIGIN'])
                && $server['HTTP_ORIGIN']!=='https://kicom.rurtalbahn.info'))
            return self::deny(404);
        if(($server['REQUEST_METHOD']??null)==='GET'){
            if($raw!=='')return self::deny(404);
            return self::result(200,'text/html; charset=utf-8',self::page($csrf));
        }
        if(($server['REQUEST_METHOD']??null)!=='POST'
            || ($server['CONTENT_TYPE']??null)!=='application/json'
            || $raw===''||strlen($raw)>16384)return self::deny(404);
        try{
            $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_is_list($p)
                || !is_string($p['csrf']??null)
                || !hash_equals($csrf,$p['csrf']))return self::deny(403);
            if(($p['step']??null)==='begin'
                &&self::exact($p,['step','csrf'])){
                $start=KiComEngramSharedHostAdminConfig::begin(
                    $trustedWebRoot,$session,$sessionId,$csrf,$p['csrf'],
                    true,$originalPasskeys,$now
                );
                return self::result(200,'application/json; charset=utf-8',
                    json_encode(['ok'=>true]+$start,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            }
            if(($p['step']??null)==='confirm'
                &&self::exact($p,['step','csrf','challenge_id','assertion','confirmation'])
                &&is_string($p['challenge_id'])
                &&is_string($p['confirmation'])
                &&is_array($p['assertion'])&&!array_is_list($p['assertion'])){
                $done=KiComEngramSharedHostAdminConfig::confirm(
                    $trustedWebRoot,$session,$sessionId,$csrf,$p['csrf'],true,
                    $p['confirmation'],$p['challenge_id'],$p['assertion'],
                    $originalPasskeys,$now
                );
                return self::result(200,'application/json; charset=utf-8',
                    json_encode($done,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            }
            return self::deny(403);
        }catch(Throwable){return self::deny(403);}
    }

    private static function page(string $csrf):string
    {
        $token=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Mirage – Hosting-Betriebsentscheidung</title>'
            .'<link rel="stylesheet" href="assets/control-plane.css"></head>'
            .'<body><main id="mirage-host-policy" data-csrf="'.$token.'">'
            .'<h1>Mirage Engram auf diesem Webspace vorbereiten</h1>'
            .'<p>Du hast den bestehenden gemeinsamen Hosting-Betrieb akzeptiert. '
            .'Andere Anwendungen desselben Hosting-Benutzers sind nicht technisch isoliert.</p>'
            .'<p>Dieser Schritt hält deine Entscheidung im privaten KiCom-Speicher fest. '
            .'Er aktiviert weder Erinnerungen noch OAuth oder den ChatGPT-Connector.</p>'
            .'<label><input type="checkbox" id="mirage-host-policy-accept">'
            .' Ich bestätige den Betrieb auf dem vorhandenen gemeinsamen Webspace.</label>'
            .'<p><button type="button" id="mirage-host-policy-button" disabled>'
            .'Mit KiCom-Passkey bestätigen</button></p>'
            .'<p id="mirage-host-policy-status" role="status"></p>'
            .'<p><a href="admin.php">Zurück zur Administration</a></p>'
            .'</main><script src="assets/mirage-host-policy-client.js" defer></script>'
            .'</body></html>';
    }
    private static function exact(array $p,array $required):bool
    {
        $a=array_keys($p);sort($a,SORT_STRING);sort($required,SORT_STRING);
        return $a===$required;
    }
    private static function result(int $status,string $type,string $body):array
    {
        return ['http_status'=>$status,
            'headers'=>self::HEADERS+['Content-Type'=>$type],
            'body'=>$body];
    }
    private static function deny(int $status):array
    {
        return self::result($status,'application/json; charset=utf-8',
            $status===403?'{"ok":false,"error":"HOST_POLICY_DENIED"}':'');
    }
}
