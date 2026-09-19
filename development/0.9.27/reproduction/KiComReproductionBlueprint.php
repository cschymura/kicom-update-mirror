<?php
declare(strict_types=1);

/**
 * KiCom 0.9.27 LAB ONLY: reproducible, inert daughter blueprint.
 *
 * A software package hash / blueprint is NOT an authority to instantiate,
 * deploy, allocate a host, inherit sessions, migrate memory, send messages,
 * or issue credentials. The independent host must verify the ACTUAL full
 * package and perform original protected onboarding and per-instance keygen.
 *
 * Strict positive schema means no arbitrary parent memory/config is copied.
 * The ordinary KiCom runtime has no private-key access here.
 */
final class KiComReproductionBlueprint
{
    private const PACKAGE_NAME = 'KiCom-0.9.26-R3.zip';
    private const EXPECTED_PACKAGE_SHA256 =
        '6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f';
    private const CAPABILITIES = [
        'kcl_https', 'dev_api', 'slack', 'mail', 'opera_browser',
        'github_mirror', 'chat_update', 'recovery'
    ];
    private const REQUIRED_BOUNDARIES = [
        'independent_policy_principal',
        'independent_recovery_trust_root',
        'per_instance_key_generation',
        'original_auth_and_action_gates',
        'full_communication_parity',
        'operator_control_and_shutdown',
        'verified_backup_and_rollback'
    ];

    private static function deny(string $reason): array
    {
        return [
            'ok'=>false, 'code'=>$reason, 'candidate_only'=>true,
            'clone_created'=>false, 'runtime_identity_issued'=>false,
            'deploy_permitted'=>false, 'external_action_authorized'=>false
        ];
    }

    /**
     * Compile a no-secrets reproduction blueprint from exact, publicly
     * verifiable identity/material. No arbitrary user-supplied assets.
     * $packageDigest MUST come from independently verified archive bytes.
     */
    public static function compile(
        string $publicParentGenome,
        string $packageDigest,
        string $parentPublicFingerprint,
        string $daughterLabel
    ): array {
        if (!preg_match('/^kicom-0\.9\.26-g25r3$/D', $publicParentGenome)
            || !hash_equals(self::EXPECTED_PACKAGE_SHA256, $packageDigest)) {
            return self::deny('PARENT_RELEASE_UNVERIFIED');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $parentPublicFingerprint)) {
            return self::deny('PARENT_PUBLIC_FINGERPRINT_INVALID');
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{2,31}$/D', $daughterLabel)) {
            return self::deny('DAUGHTER_LABEL_INVALID');
        }
        $body=[
            'format'=>'kicom-daughter-blueprint-v1',
            'parent_public_genome'=>$publicParentGenome,
            'parent_public_identity_sha256'=>$parentPublicFingerprint,
            'daughter_label'=>$daughterLabel,
            'verified_software_package'=>[
                'filename'=>self::PACKAGE_NAME,
                'sha256'=>self::EXPECTED_PACKAGE_SHA256
            ],
            'transport_contract'=>self::CAPABILITIES,
            'host_provisioning_required'=>self::REQUIRED_BOUNDARIES,
            'inheritance'=>[
                'program_and_membrane_design'=>'verified_package_only',
                'runtime_memory'=>'new_empty_instance',
                'private_credentials'=>'never',
                'parent_sessions'=>'never',
                'parent_authorizations'=>'never',
                'production_access'=>'never'
            ],
            'activation'=>'operator_authorized_independent_host_only'
        ];
        $json=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return [
            'ok'=>true, 'code'=>'DAUGHTER_BLUEPRINT_CANDIDATE',
            'candidate_only'=>true, 'clone_created'=>false,
            'runtime_identity_issued'=>false, 'deploy_permitted'=>false,
            'external_action_authorized'=>false,
            'manifest_sha256'=>hash('sha256',$json),
            'manifest_json'=>$json
        ];
    }

    /** Verify strict manifest schema without trusting the manifest's claims. */
    public static function inspect(string $json, string $expectedHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$expectedHash)
            || $json==='' || strlen($json)>8192) {
            return self::deny('BLUEPRINT_DIGEST_OR_SIZE_INVALID');
        }
        if (!hash_equals($expectedHash,hash('sha256',$json))) {
            return self::deny('BLUEPRINT_DIGEST_MISMATCH');
        }
        try {
            $body=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return self::deny('BLUEPRINT_MALFORMED');
        }
        if (!is_array($body) || array_keys($body)!==[
            'format','parent_public_genome','parent_public_identity_sha256',
            'daughter_label','verified_software_package','transport_contract',
            'host_provisioning_required','inheritance','activation'
        ]) {
            return self::deny('BLUEPRINT_SCHEMA_UNEXPECTED');
        }
        $candidate=self::compile(
            (string)($body['parent_public_genome']??''),
            (string)($body['verified_software_package']['sha256']??''),
            (string)($body['parent_public_identity_sha256']??''),
            (string)($body['daughter_label']??'')
        );
        if (!$candidate['ok']
            || !hash_equals((string)($candidate['manifest_sha256']??''), $expectedHash)
            || !hash_equals((string)($candidate['manifest_json']??''), $json)) {
            return self::deny('BLUEPRINT_NOT_CANONICAL');
        }
        return [
            'ok'=>true, 'code'=>'BLUEPRINT_BYTES_MATCH_CANONICAL_SCHEMA',
            'candidate_only'=>true, 'clone_created'=>false,
            'runtime_identity_issued'=>false, 'deploy_permitted'=>false,
            'external_action_authorized'=>false,
            'independent_package_verified_here'=>false,
            'independent_identity_verified_here'=>false,
        ];
    }
}
