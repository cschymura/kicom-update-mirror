<?php
declare(strict_types=1);

/**
 * KiCom Passkey Auth Bridge prototype.
 *
 * This module is intentionally integration-neutral: it performs WebAuthn
 * registration/assertion verification and encrypted handoff storage, but it
 * does not itself grant KiCom runtime authority. The KiCom control plane must
 * decide when enrollment is allowed and when a verified assertion may mint a
 * bounded autonomy session.
 */
final class KiComPasskeyBridge
{
    private string $storageDir;
    private string $rpId;
    private string $origin;
    private string $rpName;
    private int $challengeTtl;
    private int $enrollmentTtl;

    public function __construct(
        string $storageDir,
        string $rpId = 'kicom.rurtalbahn.info',
        string $origin = 'https://kicom.rurtalbahn.info',
        string $rpName = 'KiCom',
        int $challengeTtl = 120,
        int $enrollmentTtl = 180
    ) {
        $this->storageDir = rtrim($storageDir, '/');
        $this->rpId = strtolower(trim($rpId));
        $this->origin = rtrim(trim($origin), '/');
        $this->rpName = trim($rpName) ?: 'KiCom';
        $this->challengeTtl = max(30, min(300, $challengeTtl));
        $this->enrollmentTtl = max(60, min(600, $enrollmentTtl));
    }

    public function ready(): array
    {
        if (!function_exists('sodium_crypto_box_seal')) {
            return ['ok' => false, 'code' => 'SODIUM_REQUIRED'];
        }
        if (!function_exists('openssl_verify')) {
            return ['ok' => false, 'code' => 'OPENSSL_REQUIRED'];
        }
        if ($this->rpId === '' || $this->origin === '') {
            return ['ok' => false, 'code' => 'RP_CONFIG_INVALID'];
        }
        if (!$this->ensureStorage()) {
            return ['ok' => false, 'code' => 'STORAGE_UNAVAILABLE'];
        }
        return ['ok' => true, 'code' => 'READY'];
    }

    public function createAuthChallenge(string $clientPublicKey): array
    {
        $ready = $this->ready();
        if (!$ready['ok']) return $ready;
        $pub = self::b64uDecode($clientPublicKey);
        if ($pub === null || strlen($pub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return ['ok' => false, 'code' => 'CLIENT_PUBLIC_KEY_INVALID'];
        }
        $this->cleanup();
        $id = self::randomHex(16);
        $now = time();
        $row = [
            'schema' => 1,
            'id' => $id,
            'purpose' => 'autonomy_session',
            'state' => 'pending',
            'client_public_key' => self::b64uEncode($pub),
            'webauthn_challenge' => self::b64uEncode(random_bytes(32)),
            'created_at' => gmdate('c', $now),
            'created_epoch' => $now,
            'expires_at' => $now + $this->challengeTtl,
        ];
        if (!$this->writeJson($this->challengeFile($id), $row)) {
            return ['ok' => false, 'code' => 'CHALLENGE_WRITE_FAILED'];
        }
        return [
            'ok' => true,
            'code' => 'CHALLENGE_CREATED',
            'challenge_id' => $id,
            'expires_in' => $this->challengeTtl,
        ];
    }

    public function assertionOptions(string $challengeId): array
    {
        $x = $this->loadChallenge($challengeId, true);
        if (!$x['ok']) return $x;
        $row = $x['row'];
        if (($row['state'] ?? '') !== 'pending') {
            return ['ok' => false, 'code' => 'CHALLENGE_NOT_PENDING'];
        }
        $creds = $this->credentials();
        if (!$creds) return ['ok' => false, 'code' => 'PASSKEY_NOT_ENROLLED'];
        $allow = [];
        foreach ($creds as $c) {
            if (!is_array($c) || !is_string($c['credential_id'] ?? null)) continue;
            $allow[] = [
                'type' => 'public-key',
                'id' => (string)$c['credential_id'],
                'transports' => ['internal', 'hybrid'],
            ];
        }
        return [
            'ok' => true,
            'publicKey' => [
                'challenge' => (string)$row['webauthn_challenge'],
                'rpId' => $this->rpId,
                'allowCredentials' => $allow,
                'userVerification' => 'required',
                'timeout' => $this->challengeTtl * 1000,
            ],
        ];
    }

    public function verifyAssertion(string $challengeId, array $credential): array
    {
        $x = $this->loadChallenge($challengeId, true);
        if (!$x['ok']) return $x;
        $row = $x['row'];
        if (($row['state'] ?? '') !== 'pending') {
            return ['ok' => false, 'code' => 'CHALLENGE_NOT_PENDING'];
        }

        $rawId = self::b64uDecode((string)($credential['rawId'] ?? $credential['id'] ?? ''));
        $clientData = self::b64uDecode((string)($credential['response']['clientDataJSON'] ?? ''));
        $authData = self::b64uDecode((string)($credential['response']['authenticatorData'] ?? ''));
        $signature = self::b64uDecode((string)($credential['response']['signature'] ?? ''));
        if ($rawId === null || $clientData === null || $authData === null || $signature === null) {
            return ['ok' => false, 'code' => 'ASSERTION_ENCODING_INVALID'];
        }

        $cd = json_decode($clientData, true);
        if (!is_array($cd)) return ['ok' => false, 'code' => 'CLIENT_DATA_INVALID'];
        if (($cd['type'] ?? '') !== 'webauthn.get') return ['ok' => false, 'code' => 'CLIENT_DATA_TYPE'];
        if (!hash_equals((string)$row['webauthn_challenge'], (string)($cd['challenge'] ?? ''))) {
            return ['ok' => false, 'code' => 'CHALLENGE_MISMATCH'];
        }
        if (!hash_equals($this->origin, rtrim((string)($cd['origin'] ?? ''), '/'))) {
            return ['ok' => false, 'code' => 'ORIGIN_MISMATCH'];
        }
        $ad = $this->parseAuthenticatorData($authData, false);
        if (!$ad['ok']) return $ad;

        $credentialId = self::b64uEncode($rawId);
        $store = $this->credentialStore();
        $idx = null;
        foreach (($store['credentials'] ?? []) as $i => $c) {
            if (is_array($c) && hash_equals((string)($c['credential_id'] ?? ''), $credentialId)) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) return ['ok' => false, 'code' => 'CREDENTIAL_NOT_ENROLLED'];
        $saved = $store['credentials'][$idx];
        $pem = $this->credentialPem($saved);
        if ($pem === null) return ['ok' => false, 'code' => 'CREDENTIAL_KEY_INVALID'];
        $signed = $authData . hash('sha256', $clientData, true);
        $vr = openssl_verify($signed, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($vr !== 1) return ['ok' => false, 'code' => 'ASSERTION_SIGNATURE_INVALID'];

        $oldCount = (int)($saved['sign_count'] ?? 0);
        $newCount = (int)$ad['sign_count'];
        if ($oldCount > 0 && $newCount > 0 && $newCount <= $oldCount) {
            return ['ok' => false, 'code' => 'SIGN_COUNTER_REPLAY'];
        }
        if ($newCount > $oldCount) $store['credentials'][$idx]['sign_count'] = $newCount;
        $store['credentials'][$idx]['last_used_at'] = gmdate('c');
        if (!$this->writeJson($this->credentialsFile(), $store)) {
            return ['ok' => false, 'code' => 'CREDENTIAL_STATE_WRITE_FAILED'];
        }

        $row['state'] = 'verified';
        $row['verified_at'] = gmdate('c');
        $row['credential_id'] = $credentialId;
        if (!$this->writeJson($x['file'], $row)) {
            return ['ok' => false, 'code' => 'CHALLENGE_STATE_WRITE_FAILED'];
        }
        return ['ok' => true, 'code' => 'ASSERTION_VERIFIED', 'credential_id' => $credentialId];
    }

    public function approveWithEncryptedPayload(string $challengeId, array $payload): array
    {
        $x = $this->loadChallenge($challengeId, true);
        if (!$x['ok']) return $x;
        $row = $x['row'];
        if (($row['state'] ?? '') !== 'verified') {
            return ['ok' => false, 'code' => 'CHALLENGE_NOT_VERIFIED'];
        }
        $pub = self::b64uDecode((string)($row['client_public_key'] ?? ''));
        if ($pub === null || strlen($pub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return ['ok' => false, 'code' => 'CLIENT_PUBLIC_KEY_INVALID'];
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > 8192) {
            return ['ok' => false, 'code' => 'PAYLOAD_INVALID'];
        }
        $cipher = sodium_crypto_box_seal($json, $pub);
        $row['state'] = 'approved';
        $row['approved_at'] = gmdate('c');
        $row['ciphertext'] = self::b64uEncode($cipher);
        $row['payload_sha256'] = hash('sha256', $json);
        if (!$this->writeJson($x['file'], $row)) {
            return ['ok' => false, 'code' => 'CHALLENGE_APPROVAL_WRITE_FAILED'];
        }
        sodium_memzero($json);
        return ['ok' => true, 'code' => 'CHALLENGE_APPROVED'];
    }

    public function challengeStatus(string $challengeId): array
    {
        $x = $this->loadChallenge($challengeId, false);
        if (!$x['ok']) return $x;
        $row = $x['row'];
        $state = (string)($row['state'] ?? 'unknown');
        $out = [
            'ok' => true,
            'code' => 'CHALLENGE_STATUS',
            'challenge_id' => $challengeId,
            'state' => $state,
            'expires_at' => (int)($row['expires_at'] ?? 0),
        ];
        if ($state === 'approved' && is_string($row['ciphertext'] ?? null)) {
            $out['ciphertext'] = $row['ciphertext'];
            $out['payload_sha256'] = (string)($row['payload_sha256'] ?? '');
        }
        return $out;
    }

    public function createEnrollmentTicket(string $account = 'Christoph'): array
    {
        $ready = $this->ready();
        if (!$ready['ok']) return $ready;
        $this->cleanup();
        $id = self::randomHex(16);
        $now = time();
        $row = [
            'schema' => 1,
            'id' => $id,
            'account' => trim($account) ?: 'Christoph',
            'challenge' => self::b64uEncode(random_bytes(32)),
            'user_id' => self::b64uEncode(random_bytes(32)),
            'created_at' => gmdate('c', $now),
            'expires_at' => $now + $this->enrollmentTtl,
            'state' => 'pending',
        ];
        if (!$this->writeJson($this->enrollmentFile($id), $row)) {
            return ['ok' => false, 'code' => 'ENROLLMENT_WRITE_FAILED'];
        }
        return ['ok' => true, 'code' => 'ENROLLMENT_CREATED', 'enrollment_id' => $id, 'expires_in' => $this->enrollmentTtl];
    }

    public function registrationOptions(string $enrollmentId): array
    {
        $x = $this->loadEnrollment($enrollmentId);
        if (!$x['ok']) return $x;
        $row = $x['row'];
        if (($row['state'] ?? '') !== 'pending') return ['ok' => false, 'code' => 'ENROLLMENT_NOT_PENDING'];
        $exclude = [];
        foreach ($this->credentials() as $c) {
            if (!is_array($c) || !is_string($c['credential_id'] ?? null)) continue;
            $exclude[] = ['type' => 'public-key', 'id' => (string)$c['credential_id']];
        }
        return [
            'ok' => true,
            'publicKey' => [
                'challenge' => (string)$row['challenge'],
                'rp' => ['id' => $this->rpId, 'name' => $this->rpName],
                'user' => [
                    'id' => (string)$row['user_id'],
                    'name' => (string)$row['account'],
                    'displayName' => (string)$row['account'],
                ],
                'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
                'authenticatorSelection' => [
                    'residentKey' => 'preferred',
                    'userVerification' => 'required',
                ],
                'timeout' => $this->enrollmentTtl * 1000,
                'attestation' => 'none',
                'excludeCredentials' => $exclude,
            ],
        ];
    }

    public function completeRegistration(string $enrollmentId, array $credential, string $label = 'Passkey'): array
    {
        $x = $this->loadEnrollment($enrollmentId);
        if (!$x['ok']) return $x;
        $row = $x['row'];
        if (($row['state'] ?? '') !== 'pending') return ['ok' => false, 'code' => 'ENROLLMENT_NOT_PENDING'];

        $rawId = self::b64uDecode((string)($credential['rawId'] ?? $credential['id'] ?? ''));
        $clientData = self::b64uDecode((string)($credential['response']['clientDataJSON'] ?? ''));
        $attestation = self::b64uDecode((string)($credential['response']['attestationObject'] ?? ''));
        if ($rawId === null || $clientData === null || $attestation === null) {
            return ['ok' => false, 'code' => 'REGISTRATION_ENCODING_INVALID'];
        }
        $cd = json_decode($clientData, true);
        if (!is_array($cd)) return ['ok' => false, 'code' => 'CLIENT_DATA_INVALID'];
        if (($cd['type'] ?? '') !== 'webauthn.create') return ['ok' => false, 'code' => 'CLIENT_DATA_TYPE'];
        if (!hash_equals((string)$row['challenge'], (string)($cd['challenge'] ?? ''))) {
            return ['ok' => false, 'code' => 'CHALLENGE_MISMATCH'];
        }
        if (!hash_equals($this->origin, rtrim((string)($cd['origin'] ?? ''), '/'))) {
            return ['ok' => false, 'code' => 'ORIGIN_MISMATCH'];
        }

        $off = 0;
        try {
            $att = self::cborRead($attestation, $off);
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'ATTESTATION_CBOR_INVALID'];
        }
        if (!is_array($att) || ($att['fmt'] ?? null) !== 'none' || !is_string($att['authData'] ?? null)) {
            return ['ok' => false, 'code' => 'ATTESTATION_FORMAT_UNSUPPORTED'];
        }
        $ad = $this->parseAuthenticatorData((string)$att['authData'], true);
        if (!$ad['ok']) return $ad;
        if (!hash_equals($rawId, (string)$ad['credential_id_raw'])) {
            return ['ok' => false, 'code' => 'CREDENTIAL_ID_MISMATCH'];
        }
        $cose = $ad['cose_key'];
        if (!is_array($cose)
            || (int)($cose[1] ?? 0) !== 2
            || (int)($cose[3] ?? 0) !== -7
            || (int)($cose[-1] ?? 0) !== 1
            || !is_string($cose[-2] ?? null)
            || !is_string($cose[-3] ?? null)
            || strlen($cose[-2]) !== 32
            || strlen($cose[-3]) !== 32) {
            return ['ok' => false, 'code' => 'COSE_KEY_UNSUPPORTED'];
        }

        $credentialId = self::b64uEncode($rawId);
        $store = $this->credentialStore();
        foreach (($store['credentials'] ?? []) as $c) {
            if (is_array($c) && hash_equals((string)($c['credential_id'] ?? ''), $credentialId)) {
                return ['ok' => false, 'code' => 'CREDENTIAL_ALREADY_ENROLLED'];
            }
        }
        if (count($store['credentials'] ?? []) >= 5) return ['ok' => false, 'code' => 'CREDENTIAL_LIMIT'];
        $store['credentials'][] = [
            'credential_id' => $credentialId,
            'x' => self::b64uEncode($cose[-2]),
            'y' => self::b64uEncode($cose[-3]),
            'alg' => -7,
            'curve' => 'P-256',
            'sign_count' => (int)$ad['sign_count'],
            'user_id' => (string)$row['user_id'],
            'label' => substr(trim($label) ?: 'Passkey', 0, 80),
            'created_at' => gmdate('c'),
        ];
        if (!$this->writeJson($this->credentialsFile(), $store)) {
            return ['ok' => false, 'code' => 'CREDENTIAL_WRITE_FAILED'];
        }
        $row['state'] = 'used';
        $row['used_at'] = gmdate('c');
        $row['credential_id'] = $credentialId;
        $this->writeJson($x['file'], $row);
        return ['ok' => true, 'code' => 'PASSKEY_ENROLLED', 'credential_id' => $credentialId];
    }

    public function credentialCount(): int
    {
        return count($this->credentials());
    }

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $encoded): ?string
    {
        $encoded = trim($encoded);
        if ($encoded === '') return null;
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) return null;
        $s = strtr($encoded, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($s, true);
        return $raw === false ? null : $raw;
    }

    private function parseAuthenticatorData(string $authData, bool $requireAttested): array
    {
        if (strlen($authData) < 37) return ['ok' => false, 'code' => 'AUTH_DATA_TOO_SHORT'];
        $rpHash = substr($authData, 0, 32);
        if (!hash_equals(hash('sha256', $this->rpId, true), $rpHash)) {
            return ['ok' => false, 'code' => 'RP_ID_HASH_MISMATCH'];
        }
        $flags = ord($authData[32]);
        if (($flags & 0x01) === 0) return ['ok' => false, 'code' => 'USER_PRESENCE_REQUIRED'];
        if (($flags & 0x04) === 0) return ['ok' => false, 'code' => 'USER_VERIFICATION_REQUIRED'];
        $count = unpack('N', substr($authData, 33, 4));
        $signCount = (int)($count[1] ?? 0);
        $hasAttested = ($flags & 0x40) !== 0;
        if ($requireAttested && !$hasAttested) return ['ok' => false, 'code' => 'ATTESTED_DATA_REQUIRED'];
        $out = ['ok' => true, 'flags' => $flags, 'sign_count' => $signCount];
        if (!$hasAttested) return $out;
        if (strlen($authData) < 55) return ['ok' => false, 'code' => 'ATTESTED_DATA_TRUNCATED'];
        $offset = 37 + 16;
        $len = unpack('n', substr($authData, $offset, 2));
        $credLen = (int)($len[1] ?? 0);
        $offset += 2;
        if ($credLen < 1 || strlen($authData) < $offset + $credLen + 1) {
            return ['ok' => false, 'code' => 'CREDENTIAL_DATA_TRUNCATED'];
        }
        $credId = substr($authData, $offset, $credLen);
        $offset += $credLen;
        try {
            $cose = self::cborRead($authData, $offset);
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'COSE_CBOR_INVALID'];
        }
        $out['credential_id_raw'] = $credId;
        $out['cose_key'] = $cose;
        return $out;
    }

    private function credentialPem(array $credential): ?string
    {
        $x = self::b64uDecode((string)($credential['x'] ?? ''));
        $y = self::b64uDecode((string)($credential['y'] ?? ''));
        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) return null;
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004');
        if ($der === false) return null;
        $der .= $x . $y;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function credentialStore(): array
    {
        $x = $this->readJson($this->credentialsFile());
        if (!is_array($x) || (int)($x['schema'] ?? 0) !== 1 || !is_array($x['credentials'] ?? null)) {
            return ['schema' => 1, 'credentials' => []];
        }
        return $x;
    }

    private function credentials(): array
    {
        return array_values(array_filter($this->credentialStore()['credentials'] ?? [], 'is_array'));
    }

    private function loadChallenge(string $id, bool $requireFresh): array
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) return ['ok' => false, 'code' => 'CHALLENGE_ID_INVALID'];
        $file = $this->challengeFile($id);
        $row = $this->readJson($file);
        if (!is_array($row)) return ['ok' => false, 'code' => 'CHALLENGE_NOT_FOUND'];
        if ($requireFresh && (int)($row['expires_at'] ?? 0) < time()) {
            $row['state'] = 'expired';
            $this->writeJson($file, $row);
            return ['ok' => false, 'code' => 'CHALLENGE_EXPIRED'];
        }
        return ['ok' => true, 'file' => $file, 'row' => $row];
    }

    private function loadEnrollment(string $id): array
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) return ['ok' => false, 'code' => 'ENROLLMENT_ID_INVALID'];
        $file = $this->enrollmentFile($id);
        $row = $this->readJson($file);
        if (!is_array($row)) return ['ok' => false, 'code' => 'ENROLLMENT_NOT_FOUND'];
        if ((int)($row['expires_at'] ?? 0) < time()) {
            $row['state'] = 'expired';
            $this->writeJson($file, $row);
            return ['ok' => false, 'code' => 'ENROLLMENT_EXPIRED'];
        }
        return ['ok' => true, 'file' => $file, 'row' => $row];
    }

    private function cleanup(): void
    {
        $now = time();
        foreach ([$this->storageDir . '/challenges', $this->storageDir . '/enrollments'] as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $r = $this->readJson($f);
                if (!is_array($r) || (int)($r['expires_at'] ?? 0) < $now - 600) @unlink($f);
            }
        }
    }

    private function ensureStorage(): bool
    {
        foreach ([$this->storageDir, $this->storageDir . '/challenges', $this->storageDir . '/enrollments'] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) return false;
            @chmod($d, 0700);
            $deny = $d . '/.htaccess';
            if (!is_file($deny)) @file_put_contents($deny, "Options -Indexes\nRequire all denied\n", LOCK_EX);
        }
        if (!is_file($this->credentialsFile())) {
            if (!$this->writeJson($this->credentialsFile(), ['schema' => 1, 'credentials' => []])) return false;
        }
        return true;
    }

    private function readJson(string $file): ?array
    {
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $r = json_decode($raw, true);
        return is_array($r) ? $r : null;
    }

    private function writeJson(string $file, array $row): bool
    {
        $json = json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
        $tmp = $file . '.tmp-' . self::randomHex(6);
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) return false;
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
        @chmod($file, 0600);
        return true;
    }

    private function challengeFile(string $id): string { return $this->storageDir . '/challenges/' . $id . '.json'; }
    private function enrollmentFile(string $id): string { return $this->storageDir . '/enrollments/' . $id . '.json'; }
    private function credentialsFile(): string { return $this->storageDir . '/credentials.json'; }

    private static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private static function cborRead(string $data, int &$offset): mixed
    {
        if ($offset >= strlen($data)) throw new RuntimeException('CBOR_EOF');
        $first = ord($data[$offset++]);
        $major = $first >> 5;
        $ai = $first & 0x1f;
        $len = self::cborLength($data, $offset, $ai);
        if ($major === 0) return $len;
        if ($major === 1) return -1 - $len;
        if ($major === 2 || $major === 3) {
            if ($len < 0 || $offset + $len > strlen($data)) throw new RuntimeException('CBOR_TRUNCATED');
            $s = substr($data, $offset, $len); $offset += $len;
            return $s;
        }
        if ($major === 4) {
            $a = [];
            for ($i = 0; $i < $len; $i++) $a[] = self::cborRead($data, $offset);
            return $a;
        }
        if ($major === 5) {
            $m = [];
            for ($i = 0; $i < $len; $i++) {
                $k = self::cborRead($data, $offset);
                if (!is_int($k) && !is_string($k)) throw new RuntimeException('CBOR_MAP_KEY');
                $m[$k] = self::cborRead($data, $offset);
            }
            return $m;
        }
        if ($major === 6) {
            return self::cborRead($data, $offset);
        }
        if ($major === 7) {
            if ($ai === 20) return false;
            if ($ai === 21) return true;
            if ($ai === 22 || $ai === 23) return null;
            throw new RuntimeException('CBOR_SIMPLE_UNSUPPORTED');
        }
        throw new RuntimeException('CBOR_MAJOR_UNSUPPORTED');
    }

    private static function cborLength(string $data, int &$offset, int $ai): int
    {
        if ($ai < 24) return $ai;
        if ($ai === 24) {
            if ($offset + 1 > strlen($data)) throw new RuntimeException('CBOR_LEN');
            return ord($data[$offset++]);
        }
        if ($ai === 25) {
            if ($offset + 2 > strlen($data)) throw new RuntimeException('CBOR_LEN');
            $v = unpack('n', substr($data, $offset, 2)); $offset += 2; return (int)$v[1];
        }
        if ($ai === 26) {
            if ($offset + 4 > strlen($data)) throw new RuntimeException('CBOR_LEN');
            $v = unpack('N', substr($data, $offset, 4)); $offset += 4; return (int)$v[1];
        }
        if ($ai === 27) {
            if ($offset + 8 > strlen($data)) throw new RuntimeException('CBOR_LEN');
            $v = unpack('Nhi/Nlo', substr($data, $offset, 8)); $offset += 8;
            $n = ((int)$v['hi'] * 4294967296) + (int)$v['lo'];
            if ($n > PHP_INT_MAX) throw new RuntimeException('CBOR_INT_OVERFLOW');
            return (int)$n;
        }
        throw new RuntimeException('CBOR_INDEFINITE_UNSUPPORTED');
    }
}
