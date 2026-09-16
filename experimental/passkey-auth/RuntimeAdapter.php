<?php
declare(strict_types=1);

require_once __DIR__.'/PasskeyBridge.php';

/**
 * Narrow adapter between the standalone WebAuthn verifier and KiCom's existing
 * human/auth control plane. Authority-bearing operations remain callbacks so
 * this module cannot invent new runtime permissions.
 *
 * Required callback contracts:
 * - $verifyCurrentTotp($code, $purpose): current-counter-only FreeOTP verifier.
 * - $mintBoundedSession($context): mint exactly the normal bounded-autonomy session.
 * - $revokeMintedSession($session, $reason): revoke the just-minted session if
 *   encrypted handoff persistence fails. Prefer exact session revocation; a
 *   fail-closed all-autonomy-session revocation is acceptable only as fallback.
 * - $allowPublicChallenge($purpose): existing KiCom rate-limit / abuse gate.
 *
 * $lockDir is server-configured, never request-controlled. Per-object locks
 * prevent duplicate assertion/enrollment requests from racing into multiple
 * session mints or repeated credential enrollment.
 */
final class KiComPasskeyRuntimeAdapter
{
    private KiComPasskeyBridge $bridge;
    private Closure $verifyCurrentTotp;
    private Closure $mintBoundedSession;
    private Closure $revokeMintedSession;
    private Closure $allowPublicChallenge;
    private string $lockDir;

    public function __construct(
        KiComPasskeyBridge $bridge,
        callable $verifyCurrentTotp,
        callable $mintBoundedSession,
        callable $revokeMintedSession,
        callable $allowPublicChallenge,
        string $lockDir
    ) {
        $this->bridge = $bridge;
        $this->verifyCurrentTotp = Closure::fromCallable($verifyCurrentTotp);
        $this->mintBoundedSession = Closure::fromCallable($mintBoundedSession);
        $this->revokeMintedSession = Closure::fromCallable($revokeMintedSession);
        $this->allowPublicChallenge = Closure::fromCallable($allowPublicChallenge);
        $this->lockDir = rtrim($lockDir, '/');
    }

    public function ready(): array
    {
        $r = $this->bridge->ready();
        if (empty($r['ok'])) return $r;
        if ($this->lockDir === '') return ['ok'=>false,'code'=>'LOCK_DIR_INVALID'];
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0700, true) && !is_dir($this->lockDir)) {
            return ['ok'=>false,'code'=>'LOCK_DIR_UNAVAILABLE'];
        }
        @chmod($this->lockDir, 0700);
        $deny = $this->lockDir.'/.htaccess';
        if (!is_file($deny)) @file_put_contents($deny, "Options -Indexes\nRequire all denied\n", LOCK_EX);
        return ['ok'=>true,'code'=>'READY'];
    }

    /** Enrollment remains anchored to the existing current-counter TOTP path. */
    public function beginEnrollment(string $code, string $account = 'Christoph'): array
    {
        try {
            $verify = ($this->verifyCurrentTotp)($code, 'passkey_enrollment');
        } catch (Throwable $e) {
            return ['ok'=>false,'code'=>'TOTP_VERIFIER_EXCEPTION'];
        }
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
        return $this->withObjectLock('enrollment', $enrollmentId, function() use ($enrollmentId, $credential, $label): array {
            return $this->bridge->completeRegistration($enrollmentId, $credential, $label);
        });
    }

    /** Public/chat side: creates no session; caller must pass the existing KiCom abuse gate. */
    public function createChallenge(string $clientPublicKey): array
    {
        try {
            $gate = ($this->allowPublicChallenge)('passkey_challenge_create');
        } catch (Throwable $e) {
            return ['ok'=>false,'code'=>'CHALLENGE_GATE_EXCEPTION'];
        }
        if (!is_array($gate) || empty($gate['ok'])) {
            return is_array($gate) ? $gate : ['ok'=>false,'code'=>'CHALLENGE_GATE_INVALID'];
        }
        return $this->bridge->createAuthChallenge($clientPublicKey);
    }

    public function assertionOptions(string $challengeId): array
    {
        return $this->bridge->assertionOptions($challengeId);
    }

    /**
     * Same-origin browser submit path. The per-challenge lock spans WebAuthn
     * verification, session mint and encrypted handoff, so duplicate submits
     * cannot race into multiple normal sessions.
     */
    public function verifyAndMint(string $challengeId, array $credential): array
    {
        return $this->withObjectLock('challenge', $challengeId, function() use ($challengeId, $credential): array {
            $verified = $this->bridge->verifyAssertion($challengeId, $credential);
            if (empty($verified['ok'])) return $verified;

            $context = [
                'auth_method' => 'passkey',
                'scope' => 'autonomy',
                'credential_id' => (string)($verified['credential_id'] ?? ''),
                'challenge_id' => $challengeId,
            ];
            try {
                $session = ($this->mintBoundedSession)($context);
            } catch (Throwable $e) {
                return ['ok'=>false,'code'=>'SESSION_MINTER_EXCEPTION'];
            }
            if (!is_array($session) || empty($session['ok'])) {
                return is_array($session) ? $session : ['ok'=>false,'code'=>'SESSION_MINTER_INVALID'];
            }

            $sid = strtolower((string)($session['session_id'] ?? ''));
            $token = strtolower((string)($session['token'] ?? $session['next_token'] ?? ''));
            if (!preg_match('/^[a-f0-9]{24}$/', $sid) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
                $this->bestEffortRevoke($session, 'passkey_minter_payload_invalid');
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
            if (empty($sealed['ok'])) {
                $revoked = $this->bestEffortRevoke($session, 'passkey_encrypted_handoff_failed');
                return [
                    'ok'=>false,
                    'code'=>$revoked ? 'PASSKEY_HANDOFF_FAILED_SESSION_REVOKED' : 'PASSKEY_HANDOFF_FAILED_SESSION_REVOCATION_UNCONFIRMED',
                    'cause'=>(string)($sealed['code'] ?? 'HANDOFF_FAILED'),
                ];
            }

            return [
                'ok' => true,
                'code' => 'PASSKEY_SESSION_APPROVED',
                'challenge_id' => $challengeId,
                'credential_id' => (string)($verified['credential_id'] ?? ''),
            ];
        });
    }

    /** Public/chat side: ciphertext only; never returns plaintext session credentials. */
    public function status(string $challengeId): array
    {
        return $this->bridge->challengeStatus($challengeId);
    }

    private function bestEffortRevoke(array $session, string $reason): bool
    {
        try {
            $r = ($this->revokeMintedSession)([
                'session_id'=>(string)($session['session_id'] ?? ''),
                'reason'=>$reason,
            ], $reason);
            return is_array($r) && !empty($r['ok']);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function withObjectLock(string $kind, string $id, callable $fn): array
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return ['ok'=>false,'code'=>strtoupper($kind).'_ID_INVALID'];
        }
        $ready = $this->ready();
        if (empty($ready['ok'])) return $ready;
        $file = $this->lockDir.'/'.$kind.'-'.$id.'.lock';
        $fh = @fopen($file, 'c+');
        if ($fh === false) return ['ok'=>false,'code'=>'LOCK_OPEN_FAILED'];
        @chmod($file, 0600);
        try {
            if (!@flock($fh, LOCK_EX)) return ['ok'=>false,'code'=>'LOCK_ACQUIRE_FAILED'];
            $r = $fn();
            return is_array($r) ? $r : ['ok'=>false,'code'=>'LOCKED_OPERATION_INVALID'];
        } catch (Throwable $e) {
            return ['ok'=>false,'code'=>'LOCKED_OPERATION_EXCEPTION'];
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}
