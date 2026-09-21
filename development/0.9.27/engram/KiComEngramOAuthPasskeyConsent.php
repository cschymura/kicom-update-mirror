<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramOAuthTransactions.php';

/**
 * DEV-52: original KiCom admin PHP session -> fresh ORIGINAL KiCom WebAuthn
 * assertion -> current private passkey-owner registry -> a SINGLE approved
 * read-only OAuth code. No public OAuth route, token, login, or activation.
 *
 * Hosting code MUST instantiate the original KiComPasskeyBridge from the
 * trusted installed version and KiComEngramPrivateOwnerRegistry outside the
 * web root. No browser-supplied trust callbacks, fingerprints or owner flags.
 */
final class KiComEngramOAuthPasskeyConsent
{
    private static function admin(
        array $session,string $sessionId,string $csrf,
        string $submittedCsrf,bool $https
    ): void {
        if (!$https || ($session['admin']??null)!==true
            || strlen($sessionId)<24 || strlen($csrf)<24
            || !hash_equals($csrf,$submittedCsrf)) self::deny();
    }

    /** Authenticated admin action: show the pinned client/scope in the UI. */
    public static function review(
        PDO $db,string $requestId,array $session,
        string $sessionId,string $csrf,string $submittedCsrf,bool $https,int $now
    ): array {
        self::admin($session,$sessionId,$csrf,$submittedCsrf,$https);
        try {
            return KiComEngramOAuthTransactions::pending(
                $db,$requestId,$sessionId,$now
            );
        } catch(Throwable) {self::deny();}
    }

    /** Called ONLY after rendering the review and asking for the fresh key. */
    public static function begin(
        PDO $db,string $requestId,array &$session,
        string $sessionId,string $csrf,string $submittedCsrf,bool $https,
        KiComPasskeyBridge $bridge,int $now
    ): array {
        self::admin($session,$sessionId,$csrf,$submittedCsrf,$https);
        unset($session['mirage_oauth_pending']);
        $review=self::review(
            $db,$requestId,$session,$sessionId,$csrf,$submittedCsrf,$https,$now
        );
        try {
            if (($bridge->ready()['ok']??null)!==true)self::deny();
            $pair=sodium_crypto_box_keypair();
            try {
                $pub=KiComPasskeyBridge::b64uEncode(
                    sodium_crypto_box_publickey($pair)
                );
            } finally {
                sodium_memzero($pair);
            }
            $started=$bridge->createAuthChallenge($pub);
            if (($started['ok']??null)!==true
                || !is_string($started['challenge_id']??null))self::deny();
            $options=$bridge->assertionOptions($started['challenge_id']);
            if (($options['ok']??null)!==true
                || ($options['publicKey']['userVerification']??null)!=='required'
                || empty($options['publicKey']['allowCredentials']))self::deny();
            $session['mirage_oauth_pending']=[
                'request_hash'=>hash('sha256',$requestId),
                'challenge_id'=>$started['challenge_id'],
                'admin_session_hash'=>hash('sha256',$sessionId),
                'csrf_hash'=>hash('sha256',$csrf),
                'expires_at'=>min($now+120,$review['expires_at']),
            ];
            return [
                'challenge_id'=>$started['challenge_id'],
                'publicKey'=>$options['publicKey'],
                'review'=>$review
            ];
        } catch(Throwable) {
            unset($session['mirage_oauth_pending']);
            self::deny();
        }
    }

    /**
     * Called ONLY by the existing authenticated, CSRF-protected first-party
     * admin POST after user explicitly accepts the displayed read scope.
     * Burns the pending browser challenge BEFORE signature verification.
     */
    public static function confirm(
        PDO $db,string $requestId,array &$session,
        string $sessionId,string $csrf,string $submittedCsrf,bool $https,
        string $challengeId,array $assertion,bool $explicitReadConsent,
        KiComPasskeyBridge $bridge,
        KiComEngramPrivateOwnerRegistry $owners,
        int $now
    ): array {
        self::admin($session,$sessionId,$csrf,$submittedCsrf,$https);
        $pending=$session['mirage_oauth_pending']??null;
        unset($session['mirage_oauth_pending']); // one-use even on failed verification
        if (!$explicitReadConsent || !is_array($pending)
            || !is_string($pending['challenge_id']??null)
            || !hash_equals($pending['challenge_id'],$challengeId)
            || !hash_equals((string)($pending['request_hash']??''),hash('sha256',$requestId))
            || !hash_equals((string)($pending['admin_session_hash']??''),hash('sha256',$sessionId))
            || !hash_equals((string)($pending['csrf_hash']??''),hash('sha256',$csrf))
            || (int)($pending['expires_at']??0)<=$now)self::deny();
        try {
            // Original KiCom PasskeyBridge verifies rpId, origin, UV, challenge,
            // enrolled credential, real P-256 signature and replay counter.
            $proof=$bridge->verifyAssertion($challengeId,$assertion);
            if (($proof['ok']??null)!==true
                || !is_string($proof['credential_id']??null)
                || $proof['credential_id']==='')self::deny();
            $fp=hash('sha256',$proof['credential_id']);
            $owner=$owners($fp);
            if (!is_array($owner) || ($owner['enabled']??null)!==true
                || ($owner['subject']??null)!=='mirage-owner'
                || ($owner['credential_fingerprint']??null)!==$fp
                || ($owner['namespaces']??null)!==['project']
                || !in_array('engram.read',$owner['engram_rights']??[],true))
                self::deny();
            KiComEngramOAuthTransactions::approve(
                $db,$requestId,$sessionId,$fp,$owners,true,$now
            );
            // Code issuance now requires the SAME original PHP admin session
            // and the SAME WebAuthn-verified owner fingerprint.
            return KiComEngramOAuthTransactions::issueApprovedCode(
                $db,$requestId,$sessionId,$fp,$now
            );
        } catch(Throwable) {self::deny();}
    }

    private static function deny():never {
        throw new RuntimeException('MIRAGE_OAUTH_CONSENT_DENIED');
    }
}
