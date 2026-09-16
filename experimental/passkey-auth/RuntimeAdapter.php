<?php
declare(strict_types=1);

require_once __DIR__.'/PasskeyBridge.php';

/**
 * Narrow adapter between the standalone WebAuthn verifier and KiCom's existing
 * human/auth control plane. It deliberately receives the two authority-bearing
 * operations as callbacks so the module cannot invent new runtime authority.
 *
 * - $verifyCurrentTotp($code, $purpose) MUST use KiCom's current-counter-only
 *   verifier (no session-open grace) and return an array with ok=true on success.
 * - $mintBoundedSession($context) MUST mint only the same normal bounded
 *   autonomy session that AUTH_SESSION_OPEN would create after human auth.
 */
final class KiComPasskeyRuntimeAdapter
{
    private KiComPasskeyBridge $bridge;
    private Closure $verifyCurrentTotp;
    private Closure $mintBoundedSession;

    public function __construct(
        KiComPasskeyBridge $bridge,
        callable $verifyCurrentTotp,
        callable $mintBoundedSession
    ) {
        $this->bridge = $bridge;
        $this->verifyCurrentTotp = Closure::fromCallable($verifyCurrentTotp);
        $this->mintBoundedSession = Closure::fromCallable($mintBoundedSession);
    }

    public function ready(): array
    {
        return $this->bridge->ready();
    }

    /** Enrollment is deliberately anchored to the existing critical/current TOTP path. */
    public function beginEnrollment(string $code, string $account = 'Christoph'): array
    {
        $verify = ($this->verifyCurrentTotp)($code, 'passkey_enrollment');
        if (!is_array($verify) || empty($verify['ok'])) {
            return is_array($verify) ? $verify : ['ok'=>false,'code'=>'TOTP_VERIFIER_INVALID'];
        }
        return $this->bridge->createEnrollmentTicket($account);
    }

    public function registrationOptions(string $enrollmentId): array
    {
        return $this->bridge->registrationOptions($enrollmentId);
    }

    public function completeRegistration(string $enrollmentId, array $credential, string $label = 'Passkey'): array
    {
        return $this->bridge->completeRegistration($enrollmentId, $credential, $label);
    }

    /** Public/chat side: creates no runtime session and accepts only an ephemeral recipient key. */
    public function createChallenge(string $clientPublicKey): array
    {
        return $this->bridge->createAuthChallenge($clientPublicKey);
    }

    public function assertionOptions(string $challengeId): array
    {
        return $this->bridge->assertionOptions($challengeId);
    }

    /**
     * Same-origin browser submit path. Only after a valid assertion is verified
     * does the existing KiCom session minter run. The resulting credential is
     * sealed to the caller's ephemeral Curve25519 key before publication.
     */
    public function verifyAndMint(string $challengeId, array $credential): array
    {
        $verified = $this->bridge->verifyAssertion($challengeId, $credential);
        if (empty($verified['ok'])) return $verified;

        $context = [
            'auth_method' => 'passkey',
            'scope' => 'autonomy',
            'credential_id' => (string)($verified['credential_id'] ?? ''),
            'challenge_id' => $challengeId,
        ];
        $session = ($this->mintBoundedSession)($context);
        if (!is_array($session) || empty($session['ok'])) {
            return is_array($session) ? $session : ['ok'=>false,'code'=>'SESSION_MINTER_INVALID'];
        }

        $sid = strtolower((string)($session['session_id'] ?? ''));
        $token = strtolower((string)($session['token'] ?? $session['next_token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{24}$/', $sid) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['ok'=>false,'code'=>'SESSION_MINTER_PAYLOAD_INVALID'];
        }

        $payload = [
            'session_id' => $sid,
            'token' => $token,
            'scope' => 'autonomy',
            'auth_method' => 'passkey',
            'expires_in' => max(0, (int)($session['expires_in'] ?? 0)),
            'idle_expires_in' => max(0, (int)($session['idle_expires_in'] ?? 0)),
        ];
        $sealed = $this->bridge->approveWithEncryptedPayload($challengeId, $payload);
        if (empty($sealed['ok'])) return $sealed;

        return [
            'ok' => true,
            'code' => 'PASSKEY_SESSION_APPROVED',
            'challenge_id' => $challengeId,
            'credential_id' => (string)($verified['credential_id'] ?? ''),
        ];
    }

    /** Public/chat side: ciphertext only; never returns a plaintext session token. */
    public function status(string $challengeId): array
    {
        return $this->bridge->challengeStatus($challengeId);
    }
}
