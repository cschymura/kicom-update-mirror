<?php
declare(strict_types=1);

/**
 * KiCom Expansion/Federation v1 protocol primitives.
 *
 * Standalone candidate code. No production deployment hooks are present here.
 */
final class KiComExpansionProtocol
{
    public const SCHEMA = 1;
    public const MAX_CLOCK_SKEW = 300;

    public static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $encoded): ?string
    {
        if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) return null;
        $s = strtr($encoded, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($s, true);
        return $raw === false ? null : $raw;
    }

    public static function randomId(string $prefix = ''): string
    {
        return $prefix . bin2hex(random_bytes(12));
    }

    public static function enrollmentToken(): string
    {
        return self::b64urlEncode(random_bytes(32));
    }

    /**
     * Parent persists only this verifier, never the plaintext enrollment token.
     */
    public static function enrollmentVerifier(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @param array<string,mixed> $descriptor */
    public static function enrollmentProof(array $descriptor, string $token): string
    {
        $key = hash('sha256', $token, true);
        return hash_hmac('sha256', self::canonicalJson($descriptor), $key);
    }

    /** @param array<string,mixed> $descriptor */
    public static function verifyEnrollmentProof(array $descriptor, string $proof, string $verifierHex): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $verifierHex)) return false;
        if (!preg_match('/^[a-f0-9]{64}$/', strtolower($proof))) return false;
        $key = hex2bin($verifierHex);
        if ($key === false) return false;
        $expected = hash_hmac('sha256', self::canonicalJson($descriptor), $key);
        return hash_equals($expected, strtolower($proof));
    }

    /**
     * Create a local Ed25519 identity. Secret material remains local to the node.
     *
     * @return array{cell_id:string,public_key:string,secret_key:string}
     */
    public static function createIdentity(?string $cellId = null): array
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            throw new RuntimeException('SODIUM_REQUIRED');
        }
        $kp = sodium_crypto_sign_keypair();
        return [
            'cell_id' => $cellId ?: self::randomId('cell-'),
            'public_key' => self::b64urlEncode(sodium_crypto_sign_publickey($kp)),
            'secret_key' => self::b64urlEncode(sodium_crypto_sign_secretkey($kp)),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function signEnvelope(
        string $senderId,
        string $receiverId,
        string $operation,
        array $payload,
        string $secretKeyB64,
        ?int $issuedAt = null,
        ?string $messageId = null
    ): array {
        $secret = self::b64urlDecode($secretKeyB64);
        if ($secret === null || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('SECRET_KEY_INVALID');
        }
        $body = [
            'schema' => self::SCHEMA,
            'message_id' => $messageId ?: self::randomId('msg-'),
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'operation' => strtoupper(trim($operation)),
            'issued_at' => $issuedAt ?? time(),
            'payload_sha256' => hash('sha256', self::canonicalJson($payload)),
            'payload' => $payload,
        ];
        $signature = sodium_crypto_sign_detached(self::canonicalJson($body), $secret);
        $body['signature'] = self::b64urlEncode($signature);
        if (function_exists('sodium_memzero')) sodium_memzero($secret);
        return $body;
    }

    /**
     * @param array<string,mixed> $envelope
     * @return array{ok:bool,code:string}
     */
    public static function verifyEnvelope(
        array $envelope,
        string $expectedSender,
        string $expectedReceiver,
        string $publicKeyB64,
        ?int $now = null,
        int $maxSkew = self::MAX_CLOCK_SKEW
    ): array {
        $signatureB64 = (string)($envelope['signature'] ?? '');
        unset($envelope['signature']);
        if (($envelope['schema'] ?? null) !== self::SCHEMA) return ['ok'=>false,'code'=>'FEDERATION_SCHEMA_INVALID'];
        if (!hash_equals($expectedSender, (string)($envelope['sender_id'] ?? ''))) return ['ok'=>false,'code'=>'FEDERATION_SENDER_MISMATCH'];
        if (!hash_equals($expectedReceiver, (string)($envelope['receiver_id'] ?? ''))) return ['ok'=>false,'code'=>'FEDERATION_RECEIVER_MISMATCH'];
        $issuedAt = (int)($envelope['issued_at'] ?? 0);
        $now ??= time();
        if ($issuedAt <= 0 || abs($now - $issuedAt) > max(30, $maxSkew)) return ['ok'=>false,'code'=>'FEDERATION_MESSAGE_STALE'];
        $payload = $envelope['payload'] ?? null;
        if (!is_array($payload)) return ['ok'=>false,'code'=>'FEDERATION_PAYLOAD_INVALID'];
        $payloadHash = hash('sha256', self::canonicalJson($payload));
        if (!hash_equals($payloadHash, (string)($envelope['payload_sha256'] ?? ''))) return ['ok'=>false,'code'=>'FEDERATION_PAYLOAD_HASH_MISMATCH'];
        $pk = self::b64urlDecode($publicKeyB64);
        $sig = self::b64urlDecode($signatureB64);
        if ($pk === null || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['ok'=>false,'code'=>'FEDERATION_SIGNATURE_ENCODING_INVALID'];
        }
        $ok = sodium_crypto_sign_verify_detached($sig, self::canonicalJson($envelope), $pk);
        return $ok ? ['ok'=>true,'code'=>'FEDERATION_SIGNATURE_OK'] : ['ok'=>false,'code'=>'FEDERATION_SIGNATURE_INVALID'];
    }

    /** @param mixed $value */
    public static function canonicalJson($value): string
    {
        $normalized = self::normalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) throw new RuntimeException('JSON_ENCODING_FAILED');
        return $json;
    }

    /** @param mixed $value @return mixed */
    private static function normalize($value)
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map([self::class, 'normalize'], $value);
        ksort($value, SORT_STRING);
        foreach ($value as $k => $v) $value[$k] = self::normalize($v);
        return $value;
    }
}
