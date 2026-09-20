<?php
declare(strict_types=1);

/**
 * INTERNAL, isolated DEV candidate: retrieve a server-issued, passkey-verified
 * credential ID from KiCom's EXISTING private DEV session record.
 *
 * Instantiate only with server-owned kicomDevStore().'/sessions', never an
 * HTTP-supplied path. Call only after KiComDevSessionManager::authenticate()
 * has authenticated the same request. This reader does NOT itself verify
 * a bearer token and MUST NOT be exposed as a public route or return its
 * result to a client. A separate, operator-provisioned credential-owner map
 * and explicit consent are still required.
 *
 * The shared session lock prevents simultaneous observation of a partial
 * revoke/write, but cannot hold authorization across a later SQLite write.
 * Full atomic revocation vs in-flight write still needs dedicated live review.
 */
final class KiComEngramDevSessionCredentialReader
{
    private string $sessionsDir;

    public function __construct(string $trustedDevSessionStore)
    {
        if (is_link($trustedDevSessionStore) || !is_dir($trustedDevSessionStore)
            || (fileperms($trustedDevSessionStore) & 0077) !== 0) {
            throw new RuntimeException('ENGRAM_DEV_SESSION_STORE_INVALID');
        }
        $dir = $trustedDevSessionStore.'/sessions';
        if (is_link($dir) || !is_dir($dir) || (fileperms($dir) & 0077) !== 0) {
            throw new RuntimeException('ENGRAM_DEV_SESSION_STORE_INVALID');
        }
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('ENGRAM_DEV_SESSION_STORE_INVALID');
        }
        $this->sessionsDir = $real;
    }

    public function __invoke(array $auth): array
    {
        if (($auth['ok'] ?? null) !== true || ($auth['scope'] ?? null) !== 'dev'
            || !is_string($auth['session_id'] ?? null)
            || preg_match('/\A[a-f0-9]{24}\z/D', $auth['session_id']) !== 1) {
            return [];
        }
        $id = $auth['session_id'];
        $dir = $this->sessionsDir;
        clearstatcache(true, $dir);
        if (!is_dir($dir) || is_link($dir) || (fileperms($dir) & 0077) !== 0) {
            return [];
        }
        $lock = $dir.'/'.$id.'.lock';
        // KiComDevSessionManager::authenticate already creates the lock;
        // NEVER create a replacement lock from a reader.
        if (!is_file($lock) || is_link($lock) || (fileperms($lock) & 0077) !== 0) {
            return [];
        }
        $lockStat = @stat($lock);
        if ($lockStat === false || ($lockStat['nlink'] ?? 0) !== 1) return [];
        $h = @fopen($lock, 'rb');
        if ($h === false) return [];
        try {
            if (!flock($h, LOCK_SH)) return [];
            $file = $dir.'/'.$id.'.json';
            clearstatcache(true, $file);
            if (!is_file($file) || is_link($file) || (fileperms($file) & 0077) !== 0) return [];
            $stat = @stat($file);
            if ($stat === false || ($stat['nlink'] ?? 0) !== 1
                || ($stat['size'] ?? 0) > 16384) return [];
            $raw = @file_get_contents($file);
            if (!is_string($raw) || strlen($raw) > 16384) return [];
            $row = json_decode($raw, true);
            if (!is_array($row)
                || ($row['session_id'] ?? null) !== $id
                || ($row['scope'] ?? null) !== 'dev'
                || ($row['state'] ?? null) !== 'active'
                || ($row['auth_method'] ?? null) !== 'passkey'
                || !is_string($row['credential_id'] ?? null)
                || !is_int($row['expires_at'] ?? null)
                || !is_int($row['idle_expires_at'] ?? null)
                || $row['expires_at'] <= time()
                || $row['idle_expires_at'] <= time()) {
                return [];
            }
            return [
                'session_id' => $id,
                'auth_method' => 'passkey',
                'credential_id' => $row['credential_id'],
            ];
        } finally {
            @flock($h, LOCK_UN);
            @fclose($h);
        }
    }
}
