<?php
declare(strict_types=1);

/**
 * DEV-72 isolated verifier. The secret is supplied by the server at runtime and
 * must never be persisted in GitHub. This class does not issue OAuth tokens and
 * does not activate production writes.
 */
final class KiComEngramWriteGrant
{
    private string $secret;
    private int $maxTtl;

    public function __construct(string $secret, int $maxTtl = 300)
    {
        if (strlen($secret) < 32) throw new InvalidArgumentException('weak signing secret');
        if ($maxTtl < 30 || $maxTtl > 900) throw new InvalidArgumentException('invalid max ttl');
        $this->secret = $secret;
        $this->maxTtl = $maxTtl;
    }

    /** DEV helper: production issuance belongs to the existing consent/OAuth boundary. */
    public function issueSynthetic(array $claims): string
    {
        $this->validateShape($claims);
        $payload = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $sig = self::b64(hash_hmac('sha256', $payload, $this->secret, true));
        return $payload . '.' . $sig;
    }

    /**
     * Verify a server-signed write grant against independently verified OAuth context.
     * $oauth must come from the OAuth resource server, never MCP JSON arguments.
     */
    public function verify(string $grant, array $oauth, string $operation, int $now): array
    {
        if (!in_array($operation, ['engram_write','engram_update','engram_archive'], true)) {
            throw new RuntimeException('unsupported operation');
        }
        if (!isset($oauth['owner'],$oauth['namespace'],$oauth['connector_id'],$oauth['token_fingerprint'],$oauth['scopes']) || !is_array($oauth['scopes'])) {
            throw new RuntimeException('incomplete oauth context');
        }
        if (!in_array('engram.write', $oauth['scopes'], true)) throw new RuntimeException('engram.write scope required');
        if (count(array_filter($oauth['scopes'], static fn($s) => $s === 'engram.write')) !== 1) throw new RuntimeException('ambiguous write scope');
        [$p,$s] = array_pad(explode('.', $grant, 3), 2, null);
        if (!is_string($p) || !is_string($s) || substr_count($grant,'.') !== 1) throw new RuntimeException('malformed grant');
        $expected = self::b64(hash_hmac('sha256', $p, $this->secret, true));
        if (!hash_equals($expected, $s)) throw new RuntimeException('bad grant signature');
        $raw = self::unb64($p);
        $claims = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($claims)) throw new RuntimeException('bad grant claims');
        $this->validateShape($claims);
        if ($claims['iat'] > $now + 5 || $claims['exp'] < $now || $claims['exp'] - $claims['iat'] > $this->maxTtl) throw new RuntimeException('grant time invalid');
        foreach (['owner','namespace','connector_id','token_fingerprint'] as $k) {
            if (!hash_equals((string)$claims[$k], (string)$oauth[$k])) throw new RuntimeException('grant binding mismatch: '.$k);
        }
        if (!in_array($operation, $claims['operations'], true)) throw new RuntimeException('operation not granted');
        return ['owner'=>$claims['owner'],'namespace'=>$claims['namespace'],'grant_id'=>$claims['grant_id'],'nonce'=>$claims['nonce'],'exp'=>$claims['exp']];
    }

    private function validateShape(array $c): void
    {
        $keys=['v','grant_id','owner','namespace','connector_id','token_fingerprint','operations','nonce','iat','exp'];
        $actual=array_keys($c); sort($actual); $expected=$keys; sort($expected);
        if ($actual !== $expected || $c['v'] !== 1) throw new InvalidArgumentException('invalid grant shape');
        foreach (['grant_id','owner','namespace','connector_id','token_fingerprint','nonce'] as $k) {
            if (!is_string($c[$k]) || !preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$c[$k])) throw new InvalidArgumentException('invalid '.$k);
        }
        if (!is_array($c['operations']) || $c['operations'] === []) throw new InvalidArgumentException('operations required');
        foreach ($c['operations'] as $op) if (!in_array($op,['engram_write','engram_update','engram_archive'],true)) throw new InvalidArgumentException('invalid operation');
        if (count(array_unique($c['operations'])) !== count($c['operations'])) throw new InvalidArgumentException('duplicate operation');
        if (!is_int($c['iat']) || !is_int($c['exp']) || $c['exp'] <= $c['iat']) throw new InvalidArgumentException('invalid time claims');
    }

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function unb64(string $s): string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/D',$s)) throw new RuntimeException('invalid base64url');
        $v=base64_decode(strtr($s,'-_','+/'),true); if ($v===false) throw new RuntimeException('invalid base64url'); return $v;
    }
}
