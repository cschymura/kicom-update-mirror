<?php
declare(strict_types=1);

/**
 * Read-only, exact-byte identity check against KiCom's existing trusted pending
 * update + stored package helpers. No network, stage, install, approval or new
 * filesystem path input. Must only be loaded by KiCom's trusted internal runtime.
 */
final class KiComPamReleaseProof
{
    private const EXPECTED_R3 = '6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f';

    public static function inspectR3(): array
    {
        foreach (['kicomSelfUpdatePending','kicomSelfUpdatePackagePath','kicomSelfUpdatePackagesDir'] as $fn) {
            if (!function_exists($fn)) {
                return ['matched' => false, 'code' => 'TRUSTED_RUNTIME_UNAVAILABLE'];
            }
        }
        $pending = kicomSelfUpdatePending();
        if (!is_array($pending)) {
            return ['matched' => false, 'code' => 'NO_PENDING_PACKAGE'];
        }
        $version = (string) ($pending['to_version'] ?? '');
        $source = (string) ($pending['source'] ?? '');
        $risk = (string) ($pending['risk_class'] ?? '');
        $declared = (string) ($pending['zip_sha256'] ?? '');
        $name = (string) ($pending['package_file'] ?? '');
        $base = kicomSelfUpdatePackagesDir();
        if ($version !== '0.9.26' || $risk !== 'red'
            || !preg_match('/^[a-f0-9]{64}$/D', $declared)
            || !preg_match('/^[a-f0-9]{64}\.zip$/D', $name)
            || !hash_equals($declared . '.zip', $name)) {
            return ['matched' => false, 'code' => 'PENDING_METADATA_INVALID',
                'pending_version' => $version];
        }
        $path = kicomSelfUpdatePackagePath($pending);
        $realBase = realpath($base);
        $realPath = is_string($path) ? realpath($path) : false;
        if ($realBase === false || $realPath === false
            || dirname($realPath) !== $realBase || is_link($path)
            || !is_file($realPath)) {
            return ['matched' => false, 'code' => 'STORED_PACKAGE_UNAVAILABLE',
                'pending_version' => $version];
        }
        $bytes = filesize($realPath);
        if ($bytes === false || $bytes < 1 || $bytes > 8388608) {
            return ['matched' => false, 'code' => 'STORED_PACKAGE_SIZE_INVALID',
                'pending_version' => $version];
        }
        $actual = hash_file('sha256', $realPath);
        if (!is_string($actual) || !hash_equals($declared, $actual)) {
            return ['matched' => false, 'code' => 'STORED_PACKAGE_HASH_MISMATCH',
                'pending_version' => $version, 'pending_sha256' => $declared];
        }
        if (!hash_equals(self::EXPECTED_R3, $actual)) {
            return ['matched' => false, 'code' => 'DIFFERENT_PENDING_RELEASE',
                'pending_version' => $version, 'pending_sha256' => $actual];
        }
        return ['matched' => true, 'code' => 'EXACT_R3_PENDING_BYTES_VERIFIED',
            'pending_version' => $version, 'pending_sha256' => $actual,
            'risk' => $risk, 'source' => $source,
            'human_approval_granted' => false, 'installation_permitted' => false];
    }
}
