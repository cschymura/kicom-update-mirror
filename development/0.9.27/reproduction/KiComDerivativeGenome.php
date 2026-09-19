<?php
declare(strict_types=1);

/**
 * DEV-only derivative genome builder.
 *
 * It never edits the source R3 tree. A host must supply already verified R3
 * genome data and an independently generated daughter public key. The result
 * is a new genome document whose identity is content-bound to the immutable
 * R3 package, parent genome and daughter key. This class grants no authority,
 * deployment, recovery or network capability.
 */
final class KiComDerivativeGenome
{
    public const SOURCE_PACKAGE_SHA256 = '6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f';
    public const SOURCE_GENOME_ID = 'kicom-0.9.26-g25r3';

    public static function build(array $parent, string $daughterPublicKeyB64, string $nonceHex): array
    {
        if (($parent['id'] ?? null) !== self::SOURCE_GENOME_ID || ($parent['version'] ?? null) !== '0.9.26') {
            throw new InvalidArgumentException('unverified parent genome identity');
        }
        $pk = base64_decode($daughterPublicKeyB64, true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('invalid daughter Ed25519 public key');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $nonceHex)) {
            throw new InvalidArgumentException('invalid host nonce');
        }

        $fingerprint = hash('sha256', $pk);
        $binding = [
            'schema' => 1,
            'parent_genome_id' => self::SOURCE_GENOME_ID,
            'source_package_sha256' => self::SOURCE_PACKAGE_SHA256,
            'daughter_key_fingerprint_sha256' => $fingerprint,
            'host_nonce_sha256' => hash('sha256', hex2bin($nonceHex)),
        ];
        $canonical = json_encode($binding, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $identityDigest = hash('sha256', $canonical);

        $child = $parent;
        $child['id'] = 'kicom-child-g26-' . substr($identityDigest, 0, 24);
        $child['parent'] = self::SOURCE_GENOME_ID;
        $child['generation'] = 26;
        $child['created_at'] = 'DERIVATIVE_HOST_BOUND';
        $child['mutation_reason'] = 'isolated-derivative-genesis-key-binding';
        $child['derivative_identity'] = $binding + [
            'binding_sha256' => $identityDigest,
            'native_genome_bound' => true,
            'authority_granted' => false,
            'deployment_permitted' => false,
        ];
        return $child;
    }

    public static function verify(array $child, string $daughterPublicKeyB64): bool
    {
        $d = $child['derivative_identity'] ?? null;
        if (!is_array($d) || ($child['parent'] ?? null) !== self::SOURCE_GENOME_ID || ($child['generation'] ?? null) !== 26) {
            return false;
        }
        $pk = base64_decode($daughterPublicKeyB64, true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        if (!hash_equals((string)($d['daughter_key_fingerprint_sha256'] ?? ''), hash('sha256', $pk))) {
            return false;
        }
        $binding = [
            'schema' => $d['schema'] ?? null,
            'parent_genome_id' => $d['parent_genome_id'] ?? null,
            'source_package_sha256' => $d['source_package_sha256'] ?? null,
            'daughter_key_fingerprint_sha256' => $d['daughter_key_fingerprint_sha256'] ?? null,
            'host_nonce_sha256' => $d['host_nonce_sha256'] ?? null,
        ];
        $digest = hash('sha256', json_encode($binding, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return hash_equals((string)($d['binding_sha256'] ?? ''), $digest)
            && ($d['source_package_sha256'] ?? null) === self::SOURCE_PACKAGE_SHA256
            && ($d['parent_genome_id'] ?? null) === self::SOURCE_GENOME_ID
            && ($d['authority_granted'] ?? true) === false
            && ($d['deployment_permitted'] ?? true) === false
            && ($child['id'] ?? '') === 'kicom-child-g26-' . substr($digest, 0, 24);
    }
}
