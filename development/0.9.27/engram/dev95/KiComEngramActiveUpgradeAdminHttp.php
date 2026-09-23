<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramActiveSchemaUpgrade.php';
require_once __DIR__.'/KiComEngramPrivateOwnerRegistry.php';
require_once __DIR__.'/KiComEngramOAuthTransactions.php';
require_once __DIR__.'/KiComEngramMutationSchema.php';

/**
 * DEV-95: Original admin-session + CSRF + FRESH KiCom WebAuthn owner assertion
 * before any private ACTIVE-DB backup, DDL or signing-key preparation.
 * admin.php alone supplies its real session, fixed web root and runtime.
 * No OAuth/MCP/anonymous route imports this class.
 */
final class KiComEngramActiveUpgradeAdminHttp
{
    private const CONFIRM='FREIGABE';
    private const RESPONSE_HEADERS=[
        'Cache-Control'=>'no-store, private',
        'Content-Type'=>'application/json; charset=utf-8',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
        'Content-Security-Policy'=>"default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'"
    ];
    private static function deny(int $status=404):array {
        return self::reply($status,$status===403?'{"ok":false,"error":"FREIGABE_NICHT_ERFOLGT"}':'');
    }
    private static function reply(int $status,string $body,string $type='application/json; charset=utf-8'):array {
        $headers=self::RESPONSE_HEADERS;$headers['Content-Type']=$type;
        return ['http_status'=>$status,'headers'=>$headers,'body'=>$body];
    }
    private static function keys(array $object,array $expect):bool {
        $actual=array_keys($object);sort($actual,SORT_STRING);sort($expect,SORT_STRING);
        return $actual===$expect;
    }
    private static function runtimeHash(array $runtime):string {
        return hash('sha256',json_encode($runtime,JSON_THROW_ON_ERROR));
    }
    public static function handle(
        array $server,string $raw,array &$session,string $sessionId,string $csrf,
        string $webRoot,array $trustedRuntime,KiComPasskeyBridge $passkeys,int $now
    ):array {
        if(($server['HTTPS']??null)!=='on'
            ||($server['HTTP_HOST']??null)!=='kicom.rurtalbahn.info'
            ||($session['admin']??null)!==true
            ||strlen($sessionId)<24||strlen($csrf)<24
            ||!is_string($session['csrf']??null)
            ||!hash_equals($session['csrf'],$csrf)
            ||(isset($server['HTTP_ORIGIN'])
                &&$server['HTTP_ORIGIN']!=='https://kicom.rurtalbahn.info'))
            return self::deny();
        $method=$server['REQUEST_METHOD']??null;
        if($method==='GET') {
            if($raw!=='')return self::deny();
            try {
                // Readiness check does not create a private database or alter scope.
                KiComEngramActiveSchemaUpgrade::preflight($webRoot,$trustedRuntime);
                $token=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                $html='<!doctype html><html lang="de"><head><meta charset="utf-8">'
                    .'<meta name="viewport" content="width=device-width,initial-scale=1">'
                    .'<title>KiCom – Datenbankvorbereitung</title>'
                    .'<link rel="stylesheet" href="assets/control-plane.css"></head>'
                    .'<body><main id="mirage-active-upgrade" data-csrf="'.$token.'">'
                    .'<h1>KiCom – Datenbanken vorbereiten</h1>'
                    .'<p>Bestehende Datenbanken privat sichern und zusätzliche Funktionen vorbereiten. '
                    .'Bestehende Erinnerungen und Lesezugriff bleiben erhalten. '
                    .'Schreibzugriff wird hier nicht freigeschaltet.</p>'
                    .'<button id="mirage-active-upgrade-button" type="button">FREIGABE</button>'
                    .'<p id="mirage-active-upgrade-status" role="status"></p>'
                    .'<p><a href="admin.php">Zurück</a></p></main>'
                    .'<script src="assets/mirage-active-upgrade-client.js" defer></script>'
                    .'</body></html>';
                return self::reply(200,$html,'text/html; charset=utf-8');
            }catch(Throwable){return self::deny(409);}
        }
        if($method!=='POST'||($server['CONTENT_TYPE']??null)!=='application/json'
            ||$raw===''||strlen($raw)>16384)return self::deny();
        try {
            $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_is_list($p)
                ||!is_string($p['csrf']??null)
                ||!hash_equals($csrf,$p['csrf']))return self::deny(403);
            if(($p['step']??null)==='begin'&&self::keys($p,['step','csrf'])) {
                unset($session['mirage_active_upgrade_pending']);
                KiComEngramActiveSchemaUpgrade::preflight($webRoot,$trustedRuntime);
                if(($passkeys->ready()['ok']??null)!==true) return self::deny(403);
                $pair=sodium_crypto_box_keypair();
                try {
                    $pub=KiComPasskeyBridge::b64uEncode(sodium_crypto_box_publickey($pair));
                }finally{sodium_memzero($pair);}
                $challenge=$passkeys->createAuthChallenge($pub);
                if(($challenge['ok']??null)!==true||!is_string($challenge['challenge_id']??null))
                    return self::deny(403);
                $opts=$passkeys->assertionOptions($challenge['challenge_id']);
                if(($opts['ok']??null)!==true
                    ||($opts['publicKey']['userVerification']??null)!=='required'
                    ||empty($opts['publicKey']['allowCredentials']))return self::deny(403);
                $session['mirage_active_upgrade_pending']=[
                    'challenge_id'=>$challenge['challenge_id'],
                    'session_hash'=>hash('sha256',$sessionId),
                    'csrf_hash'=>hash('sha256',$csrf),
                    'runtime_hash'=>self::runtimeHash($trustedRuntime),
                    'expires_at'=>$now+120
                ];
                return self::reply(200,json_encode(['ok'=>true,
                    'challenge_id'=>$challenge['challenge_id'],
                    'publicKey'=>$opts['publicKey'],'confirmation'=>self::CONFIRM
                ],JSON_THROW_ON_ERROR));
            }
            if(($p['step']??null)==='confirm'
                &&self::keys($p,['step','csrf','challenge_id','assertion','confirmation'])
                &&is_string($p['challenge_id']??null)
                &&is_array($p['assertion']??null)&&!array_is_list($p['assertion'])
                &&($p['confirmation']??null)===self::CONFIRM) {
                $pending=$session['mirage_active_upgrade_pending']??null;
                unset($session['mirage_active_upgrade_pending']);
                if(!is_array($pending)
                    ||!hash_equals((string)($pending['challenge_id']??''),$p['challenge_id'])
                    ||!hash_equals((string)($pending['session_hash']??''),hash('sha256',$sessionId))
                    ||!hash_equals((string)($pending['csrf_hash']??''),hash('sha256',$csrf))
                    ||!hash_equals((string)($pending['runtime_hash']??''),self::runtimeHash($trustedRuntime))
                    ||(int)($pending['expires_at']??0)<=$now)return self::deny(403);
                $proof=$passkeys->verifyAssertion($p['challenge_id'],$p['assertion']);
                if(($proof['ok']??null)!==true||!is_string($proof['credential_id']??null)
                    ||$proof['credential_id']==='')return self::deny(403);
                $fingerprint=hash('sha256',$proof['credential_id']);
                $web=realpath($webRoot);
                if(!is_string($web))return self::deny(403);
                $registry=new KiComEngramPrivateOwnerRegistry(
                    dirname($web).'/engram-private/owners/engram-owners.json',$web
                );
                $owner=$registry($fingerprint);
                if(!is_array($owner)||($owner['enabled']??null)!==true
                    ||($owner['subject']??null)!=='mirage-owner'
                    ||($owner['credential_fingerprint']??null)!==$fingerprint
                    ||($owner['namespaces']??null)!==['project']
                    ||!in_array('engram.read',$owner['engram_rights']??[],true)
                    ||!hash_equals((string)($trustedRuntime['owner_binding']??''),
                        hash('sha256',"mirage-owner\0".$fingerprint)))
                    return self::deny(403);
                $result=KiComEngramActiveSchemaUpgrade::applyAfterVerifiedPasskey(
                    $webRoot,$trustedRuntime
                );
                return self::reply(200,json_encode($result,JSON_THROW_ON_ERROR));
            }
            return self::deny(403);
        }catch(Throwable) {
            // No backend paths, schema contents, owner IDs or passkey details.
            return self::deny(403);
        }
    }
}
