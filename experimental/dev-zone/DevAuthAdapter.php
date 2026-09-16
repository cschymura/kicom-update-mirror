<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/passkey-auth/PasskeyBridge.php';
require_once __DIR__.'/DevSession.php';

/**
 * Binds successful passkey assertions to practical DEV sessions.
 *
 * The adapter serializes verification+issuance per challenge so a double tap or
 * concurrent request cannot mint two DEV sessions from the same WebAuthn challenge.
 * It intentionally has no production/self-update/kernel callbacks.
 */
final class KiComDevAuthAdapter
{
    private KiComPasskeyBridge $passkeys;
    private KiComDevSessionManager $sessions;
    private string $lockDir;

    public function __construct(
        KiComPasskeyBridge $passkeys,
        KiComDevSessionManager $sessions,
        string $lockDir
    ) {
        $this->passkeys = $passkeys;
        $this->sessions = $sessions;
        $this->lockDir = rtrim($lockDir, '/');
    }

    public function ready(): array
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0700, true) && !is_dir($this->lockDir)) {
            return ['ok' => false, 'code' => 'DEV_AUTH_LOCKDIR_UNAVAILABLE'];
        }
        @chmod($this->lockDir, 0700);
        $p = $this->passkeys->ready();
        if (empty($p['ok'])) return $p;
        return $this->sessions->ready();
    }

    /**
     * Verifies one WebAuthn assertion and returns one non-rotating DEV session.
     * The returned token is scoped to DEV capabilities only.
     */
    public function verifyAndIssue(string $challengeId, array $credential, string $label = 'KiCom DEV'): array
    {
        $challengeId = strtolower(trim($challengeId));
        if (!preg_match('/^[a-f0-9]{32}$/', $challengeId)) {
            return ['ok' => false, 'code' => 'CHALLENGE_ID_INVALID'];
        }
        $ready = $this->ready();
        if (empty($ready['ok'])) return $ready;

        $lockFile = $this->lockDir.'/'.$challengeId.'.lock';
        $fh = @fopen($lockFile, 'c+');
        if ($fh === false) return ['ok' => false, 'code' => 'DEV_AUTH_LOCK_FAILED'];
        try {
            if (!flock($fh, LOCK_EX)) return ['ok' => false, 'code' => 'DEV_AUTH_LOCK_FAILED'];

            $verified = $this->passkeys->verifyAssertion($challengeId, $credential);
            if (empty($verified['ok'])) return $verified;

            $session = $this->sessions->issue([
                'auth_method' => 'passkey',
                'credential_id' => (string)($verified['credential_id'] ?? ''),
                'label' => $label,
            ]);
            if (empty($session['ok'])) return $session;

            return $session + [
                'auth_method' => 'passkey',
                'challenge_id' => $challengeId,
            ];
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}
