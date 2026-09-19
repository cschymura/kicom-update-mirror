<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPam.php';
require_once __DIR__ . '/KiComPamKclAdapter.php';
require_once __DIR__ . '/KiComPamReleaseProof.php';

/**
 * Bounded observation -> internal READ-ONLY proof -> persisted result cycle.
 *
 * This class has zero release/install/capability/authorization methods.
 * It never calls arbitrary HTTP, SQL, shell, deployment or an action callback.
 * A trusted caller supplies only responses already fetched from the adapter's
 * four fixed KCL read endpoints. Package bytes are read solely through the
 * pre-existing KiCom pending/package helpers (KiComPamReleaseProof).
 */
final class KiComPamReadOnlyCycle
{
    public function __construct(
        private KiComPam $pam,
        private KiComPamKclAdapter $kcl
    ) {}

    public function run(string $runKey, array $trustedKclResponses): array
    {
        $state = $this->kcl->capture($runKey, $trustedKclResponses);
        if (empty($state['genome_healthy']) || empty($state['sqlite_quick_check'])) {
            return ['code' => 'HEALTH_UNVERIFIED', 'checkpoint_id' => $state['checkpoint_id']];
        }
        if (empty($state['protected_install_pending'])
            || ($state['version'] ?? '') !== '0.9.25') {
            return ['code' => 'NO_SUPPORTED_RED_RELEASE_REVIEW',
                'checkpoint_id' => $state['checkpoint_id']];
        }
        $proof = KiComPamReleaseProof::inspectR3();
        $expected = '6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f';
        if (empty($proof['matched'])
            || ($proof['pending_version'] ?? '') !== '0.9.26'
            || ($proof['pending_sha256'] ?? '') !== $expected) {
            $code = (string)($proof['code'] ?? 'PENDING_IDENTITY_UNKNOWN');
            $this->pam->observe('KiCom:0.9.25', 'pending-r3-identity', 'DEGRADED',
                'trusted-pending-package-proof', hash('sha256', 'R3:' . $code), 60);
            return ['code' => 'PENDING_IDENTITY_UNVERIFIED',
                'detail' => $code, 'checkpoint_id' => $state['checkpoint_id']];
        }

        // Key is bound to exact package identity, not merely version.
        $key = 'readonly-r3-identity:' . $expected;
        $context = hash('sha256', 'KiCom:0.9.25:RED:read-only-proof:' . $expected);
        $action = $this->pam->queue($key,
            'Record independent read-only R3 package identity', $context, 'internal');
        $leaseSha = bin2hex(random_bytes(32));
        if (!$this->pam->claimInternal($action, $leaseSha, 60)) {
            // Never steal/auto-replay an expired or uncertain lease.
            return ['code' => 'PROOF_ALREADY_CHECKED_OR_UNCERTAIN',
                'checkpoint_id' => $state['checkpoint_id'], 'action_id' => $action];
        }
        // Re-evaluate the actual bounded pending metadata and bytes AFTER
        // claiming to reject a package superseded between observation and work.
        $again = KiComPamReleaseProof::inspectR3();
        $stillSame = !empty($again['matched'])
            && ($again['pending_sha256'] ?? '') === $expected
            && ($again['pending_version'] ?? '') === '0.9.26';
        $evidenceSha = hash('sha256', json_encode([
            'code' => (string)($again['code'] ?? 'UNKNOWN'),
            'sha' => (string)($again['pending_sha256'] ?? ''),
            'version' => (string)($again['pending_version'] ?? '')
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (!$this->pam->finish($action, $leaseSha,
            $stillSame ? 'SUCCEEDED' : 'BLOCKED', $evidenceSha)) {
            // A lost lease/result must never be described as successful.
            return ['code' => 'PROOF_RESULT_UNCERTAIN',
                'checkpoint_id' => $state['checkpoint_id'], 'action_id' => $action];
        }
        if (!$stillSame) {
            return ['code' => 'PENDING_CHANGED_DURING_PROOF',
                'checkpoint_id' => $state['checkpoint_id'], 'action_id' => $action];
        }
        $this->pam->observe('KiCom:0.9.25', 'pending-r3-identity', 'AVAILABLE',
            'trusted-pending-package-proof', $evidenceSha, 60);
        return [
            'code' => 'READ_ONLY_R3_IDENTITY_CONFIRMED',
            'checkpoint_id' => $state['checkpoint_id'],
            'action_id' => $action,
            'pending_sha256' => $expected,
            'human_approval_granted' => false,
            'installation_permitted' => false
        ];
    }
}
