<?php
declare(strict_types=1);

/**
 * DEV-only adapter between KiCom's successful, server-side PASSKEY session
 * and a separate, explicitly provisioned Engram owner/rights registry.
 *
 * This is not a session authenticator. Call ONLY after the existing KiCom
 * session manager has authenticated the bearer; both callbacks must be trusted
 * private server code. The client never supplies the credential ID, owner,
 * rights, or the registry. A DEV session ID or a user-editable label is NOT a
 * durable private-memory identity.
 *
 * sessionCredential($auth): returns the credential ID from the already
 * authenticated server-side session record and its issuance auth_method.
 * ownerByFingerprint($sha256): returns a separately provisioned, enabled
 * owner/namespace/rights binding for that specific verified passkey.
 *
 * Production integration still must verify real passkey enrollment, revocation
 * and origin of the registry, and implement sessionCredential in protected
 * KiCom runtime code; these are deliberately NOT inferred from bearer headers.
 */
final class KiComEngramVerifiedCredentialResolver
{
    private $sessionCredential;
    private $ownerByFingerprint;

    public function __construct(callable $sessionCredential, callable $ownerByFingerprint)
    {
        $this->sessionCredential = $sessionCredential;
        $this->ownerByFingerprint = $ownerByFingerprint;
    }

    public function __invoke(array $auth): array
    {
        $deny = static fn(): array => ['verified' => false];

        if (($auth['ok'] ?? null) !== true
            || ($auth['scope'] ?? null) !== 'dev'
            || !is_string($auth['session_id'] ?? null)
            || preg_match('/\A[a-f0-9]{24}\z/D', $auth['session_id']) !== 1) {
            return $deny();
        }

        // This callback MUST obtain the credential from KiCom's protected
        // session record, not from the request or the session's display label.
        try {
            $session = ($this->sessionCredential)($auth);
        } catch (Throwable $e) {
            return $deny();
        }
        if (!is_array($session)
            || ($session['auth_method'] ?? null) !== 'passkey'
            || !is_string($session['session_id'] ?? null)
            || !hash_equals($auth['session_id'], $session['session_id'])
            || !is_string($session['credential_id'] ?? null)
            || strlen($session['credential_id']) < 16
            || strlen($session['credential_id']) > 512
            || preg_match('/\A[A-Za-z0-9_-]+\z/D', $session['credential_id']) !== 1) {
            return $deny();
        }

        $fingerprint = hash('sha256', $session['credential_id']);
        try {
            $owner = ($this->ownerByFingerprint)($fingerprint);
        } catch (Throwable $e) {
            return $deny();
        }
        if (!is_array($owner) || ($owner['enabled'] ?? null) !== true
            || !is_string($owner['credential_fingerprint'] ?? null)
            || !hash_equals($fingerprint, $owner['credential_fingerprint'])
            || !is_string($owner['subject'] ?? null)
            || preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $owner['subject']) !== 1
            || !is_array($owner['namespaces'] ?? null)
            || !is_array($owner['engram_rights'] ?? null)) {
            return $deny();
        }

        // Permit only a finite, explicit set of fixed names and privileges.
        $namespaces = array_values($owner['namespaces']);
        $rights = array_values($owner['engram_rights']);
        if ($namespaces === [] || count($namespaces) > 8
            || count($namespaces) !== count(array_unique($namespaces))
            || $rights === [] || count($rights) > 2
            || count($rights) !== count(array_unique($rights))) {
            return $deny();
        }
        foreach ($namespaces as $namespace) {
            if (!is_string($namespace)
                || preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $namespace) !== 1) {
                return $deny();
            }
        }
        foreach ($rights as $right) {
            if (!is_string($right)
                || !in_array($right, ['engram.read', 'engram.write'], true)) {
                return $deny();
            }
        }

        // Return ONLY what the scoped memory adapter needs. Never reflect
        // credential ID, fingerprint, session token or registry metadata.
        return [
            'verified' => true,
            'subject' => $owner['subject'],
            'namespaces' => $namespaces,
            'engram_rights' => $rights,
        ];
    }
}
