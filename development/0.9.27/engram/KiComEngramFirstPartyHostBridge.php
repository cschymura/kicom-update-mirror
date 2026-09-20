<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramWebAuthnApprovalController.php';
require_once __DIR__.'/KiComEngramWebAuthnReviewHttpAdapter.php';
require_once __DIR__.'/KiComEngramBrowserReviewPage.php';
require_once __DIR__.'/KiComEngramPrivatePathProbe.php';

/**
 * First-party adapter for KiCom's EXISTING password-protected admin.php
 * PHP session, with a *separate* fresh WebAuthn assertion for each consent.
 *
 * Intentionally NO automatic installation or runtime config creation. Both
 * review and memory routes are 404 unless the host independently injects
 * separately approved server-owned private runtime configuration.
 *
 * Real owner/permissions derive from operator-configured subject + signed
 * passkey mapping in the private owner registry, NEVER request parameters.
 */
final class KiComEngramFirstPartyHostBridge
{
    private static function trusted(array $runtime): bool
    {
        foreach (['enabled','operator_approved','host_isolation_verified',
                  'review_enabled'] as $flag) {
            if (($runtime[$flag]??null)!==true) return false;
        }
        if (($runtime['runtime_source']??null)!=='server-only-reviewed'
            || ($runtime['private_memory_scope']??null)!=='dev-verified-owner'
            || !is_string($runtime['admin_subject']??null)
            || preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D',$runtime['admin_subject'])!==1
            || !is_string($runtime['expected_origin']??null)
            || $runtime['expected_origin']!=='https://kicom.rurtalbahn.info'
            || ($runtime['rp_id']??null)!=='kicom.rurtalbahn.info') return false;
        foreach (['web_root','data_dir','backups_dir','owner_registry',
                  'consent_dir','review_dir','stepup_dir','passkey_store'] as $key) {
            if (!is_string($runtime[$key]??null)
                || $runtime[$key]==='' || $runtime[$key][0]!==DIRECTORY_SEPARATOR) return false;
        }
        $roots=$runtime['reviewed_web_roots']??null;
        if (!is_array($roots) || !in_array($runtime['web_root'],$roots,true)) return false;
        return true;
    }

    private static function requireAdmin(array $runtime,array $session,string $sessionId): void
    {
        if (!self::trusted($runtime)
            || ($session['admin']??null)!==true
            || strlen($sessionId)<24
            || !is_string($session['csrf']??null)
            || strlen($session['csrf'])<24) {
            throw new RuntimeException('ENGRAM_FIRST_PARTY_UNAVAILABLE');
        }
        // Directory checks alone cannot enumerate external HTTP aliases or
        // prove PHP same-UID isolation: operator must independently attest.
        KiComEngramPrivatePathProbe::runAgainstWebRoots(
            $runtime['data_dir'],$runtime['backups_dir'],
            $runtime['reviewed_web_roots']
        );
    }

    private static function controller(
        array $runtime,array $session,string $sessionId
    ): KiComEngramWebAuthnApprovalController {
        self::requireAdmin($runtime,$session,$sessionId);
        $web=$runtime['web_root'];
        $ownerRegistry=new KiComEngramPrivateOwnerRegistry(
            $runtime['owner_registry'],$web
        );
        $bridge=new KiComPasskeyBridge(
            $runtime['passkey_store'],$runtime['rp_id'],
            $runtime['expected_origin'],'KiCom'
        );
        if (($bridge->ready()['ok']??null)!==true) {
            throw new RuntimeException('ENGRAM_PASSKEY_UNAVAILABLE');
        }
        // The external browserIdentity callback reads ONLY the already
        // authenticated server-side admin session captured at routing time.
        $identity=static function(array $context)use($runtime,$session,$sessionId):array{
            if (($context['trusted_admin_session']??null)!==true
                || ($session['admin']??null)!==true
                || !hash_equals($sessionId,(string)($context['session_id']??''))) {
                return ['verified'=>false];
            }
            return [
                'verified'=>true,'first_party_browser'=>true,
                'subject'=>$runtime['admin_subject'],
                'browser_session_id'=>$sessionId,
                'namespaces'=>['project'],
                'engram_rights'=>['engram.read','engram.write'],
            ];
        };
        return new KiComEngramWebAuthnApprovalController(
            $runtime['stepup_dir'],$runtime['review_dir'],
            $runtime['consent_dir'],$web,$bridge,$ownerRegistry,$identity
        );
    }

    private static function context(string $sessionId): array
    {
        return ['trusted_admin_session'=>true,'session_id'=>$sessionId];
    }

    private static function renderPage(
        KiComEngramWebAuthnApprovalController $controller,
        string $reviewId,string $sessionId
    ): string {
        $html=$controller->render($reviewId,self::context($sessionId));
        return KiComEngramBrowserReviewPage::decorate(
            $html,'/api.php?q=ENGRAM_REVIEW','/assets/engram-review-client.js'
        );
    }

    /** Called ONLY by already session-authenticated admin.php, not an API. */
    public static function adminPage(
        array $runtime,array $session,string $sessionId,
        array $server,array $get,array $post
    ): array {
        $fail=static fn(int $status):array=>[
            'status'=>$status,'content_type'=>'text/plain; charset=utf-8',
            'body'=>'Engram review unavailable',
        ];
        if (($server['HTTPS']??'')!=='on') return $fail(404);
        try {
            self::requireAdmin($runtime,$session,$sessionId);
            $controller=self::controller($runtime,$session,$sessionId);
            if (($server['REQUEST_METHOD']??'GET')==='POST') {
                if (!is_string($post['csrf']??null)
                    || !hash_equals($session['csrf'],$post['csrf'])
                    || !is_string($post['body']??null)
                    || !is_string($post['source_ref']??null)
                    || array_keys($post)!==['csrf','body','source_ref']
                    || strlen($post['body'])>4096
                    || strlen($post['source_ref'])>90) return $fail(403);
                // Here the operator explicitly stages one item for DISPLAY.
                // This does NOT issue consent. Only the signed, fresh WebAuthn
                // confirmation in the separate same-origin JSON route can.
                $entry=[
                    'namespace'=>'project','kind'=>'technical',
                    'body'=>$post['body'],'source_kind'=>'explicit_user',
                    'source_ref'=>$post['source_ref'],'sensitivity'=>'ordinary',
                ];
                $id=$controller->stage(self::context($sessionId),$entry);
                return ['status'=>200,'content_type'=>'text/html; charset=utf-8',
                    'body'=>self::renderPage($controller,$id,$sessionId)];
            }
            if (($server['REQUEST_METHOD']??'GET')==='GET'
                && is_string($get['review_id']??null)) {
                return ['status'=>200,'content_type'=>'text/html; charset=utf-8',
                    'body'=>self::renderPage(
                        $controller,$get['review_id'],$sessionId
                    )];
            }
            if (($server['REQUEST_METHOD']??'GET')!=='GET') return $fail(405);
            $csrf=htmlspecialchars($session['csrf'],ENT_QUOTES,'UTF-8');
            $form='<!doctype html><html lang="de"><head><meta charset="utf-8">'
                .'<meta name="viewport" content="width=device-width,initial-scale=1">'
                .'<title>KiCom Engram – Erinnerung prüfen</title></head><body>'
                .'<main><h1>Einzelne Erinnerung zur Prüfung vorlegen</h1>'
                .'<p>Erst eine gesonderte Passkey-Bestätigung gibt genau den'
                .' anschließend angezeigten Inhalt frei.</p>'
                .'<form method="post" action="/admin.php?engram_review=1">'
                .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                .'<label>Erinnerung<textarea name="body" maxlength="4096" required></textarea></label>'
                .'<label>Quelle<input name="source_ref" maxlength="90" '
                .'placeholder="note:synthetic-01" required></label>'
                .'<button type="submit">Zur Einzelprüfung anzeigen</button>'
                .'</form></main></body></html>';
            return ['status'=>200,'content_type'=>'text/html; charset=utf-8','body'=>$form];
        } catch(Throwable $e) { return $fail(404); }
    }

    /** Called ONLY by the allowlisted api.php first-party review branch. */
    public static function reviewApi(
        array $runtime,array $session,string $sessionId,
        array $server,string $body
    ): array {
        $noStore=['Cache-Control'=>'no-store, private',
            'Content-Type'=>'application/json; charset=utf-8',
            'X-Content-Type-Options'=>'nosniff',
            'X-Frame-Options'=>'DENY'];
        $fail=static fn(int $status):array=>[
            'http_status'=>$status,'headers'=>$noStore,
            'body'=>['ok'=>false,'code'=>'ENGRAM_REVIEW_UNAVAILABLE'],
        ];
        try {
            self::requireAdmin($runtime,$session,$sessionId);
            $controller=self::controller($runtime,$session,$sessionId);
            $adapter=new KiComEngramWebAuthnReviewHttpAdapter(
                $controller,
                static function(array $ignored)use($sessionId):array{
                    return self::context($sessionId);
                },
                $runtime['expected_origin']
            );
            return $adapter->handle($server,$body);
        } catch(Throwable $e) {return $fail(404);}
    }
}
