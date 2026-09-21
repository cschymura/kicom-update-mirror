<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramInactiveDbProvisioner.php';

/**
 * DEV-60: first-party admin UI for ONE existing missing piece of live 0.9.32.
 *
 * Caller MUST be the original KiCom admin.php, with real $_SESSION,
 * session_id(), csrf() and immutable __DIR__. This class does not install
 * itself, activate memories, change host config, mint tokens or overwrite data.
 */
final class KiComEngramAdminDbProvisionHttp
{
    public static function handle(
        array $http, array $query, array $form, array $session,
        string $sessionId, string $csrf, string $webRoot
    ): array {
        $headers=[
            'Cache-Control'=>'no-store, private',
            'Content-Type'=>'text/html; charset=utf-8',
            'X-Content-Type-Options'=>'nosniff',
            'X-Frame-Options'=>'DENY',
            'Referrer-Policy'=>'no-referrer',
        ];
        $deny=static fn(int $status):array=>[
            'http_status'=>$status,'headers'=>$headers,'body'=>''
        ];
        if (($http['HTTPS']??null)!=='on'
            || ($http['HTTP_HOST']??null)!=='kicom.rurtalbahn.info'
            || ($session['admin']??null)!==true
            || !is_string($session['csrf']??null)
            || !hash_equals($session['csrf'],$csrf)
            || strlen($sessionId)<24
            || $query!==['engram_db_setup'=>'1'])return $deny(404);
        $method=$http['REQUEST_METHOD']??'';
        if($method!=='GET'&&$method!=='POST')return $deny(405);
        if($method==='POST'&&
            (($http['HTTP_ORIGIN']??null)!=='https://kicom.rurtalbahn.info'
            || count($form)!==2 || !isset($form['csrf'],$form['confirmation'])
            || !is_string($form['csrf'])||!is_string($form['confirmation'])
            || !hash_equals($csrf,$form['csrf'])))return $deny(403);

        try {
            $runtime=KiComEngramInactiveDbProvisioner::loadInactiveRuntime($webRoot);
            // A GET NEVER initializes a database. All initial flags come from
            // the REAL existing server-owned inactive private host config.
            $status='Bereit zur Vorbereitung. Die private Erinnerung bleibt deaktiviert.';
            if ($method==='POST') {
                $res=KiComEngramInactiveDbProvisioner::prepare(
                    $webRoot,$session,$sessionId,$csrf,$form['csrf'],
                    $form['confirmation'],$runtime,true
                );
                $status=($res['code']??null)==='PRIVATE_DATABASES_PREPARED_INACTIVE'
                    ? 'Beide privaten Datenbankschemata sind vorbereitet. Engram bleibt deaktiviert.'
                    : 'Vorbereitung nicht abgeschlossen.';
            }
        }catch(Throwable){
            // Do not leak private host paths, owner identity, file contents or
            // database diagnostics in public HTML.
            return $deny(409);
        }
        $token=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $message=htmlspecialchars($status,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return ['http_status'=>200,'headers'=>$headers,
            'body'=>'<!doctype html><html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>KiCom – Private OAuth-Datenbanken</title></head><body><main>'
            .'<h1>Engram – Datenbanken vorbereiten</h1>'
            .'<p>Bereitet nur fehlende OAuth- und Aktivierungsdatenbanken vor. '
            .'Bestehende Erinnerungen, Hostkonfiguration und Passkeys bleiben unverändert.</p>'
            .'<p role="status">'.$message.'</p>'
            .'<form action="admin.php?engram_db_setup=1" method="post">'
            .'<input type="hidden" name="csrf" value="'.$token.'">'
            .'<label>Bestätige mit dem Text '
            .'<code>INAKTIVE ENGRAM DATENBANKEN VORBEREITEN</code>'
            .'<input name="confirmation" required autocomplete="off"></label>'
            .'<button type="submit">Fehlende Datenbanken vorbereiten</button></form>'
            .'<p><a href="admin.php?engram_setup=1">Zur Engram-Einrichtung</a></p>'
            .'</main></body></html>'
        ];
    }
}
