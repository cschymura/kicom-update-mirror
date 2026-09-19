<?php
declare(strict_types=1);

/**
 * DEV ONLY. Read-only evidence for the original SQLite DB/WAL/SHM triad.
 *
 * The target path comes ONLY from the trusted KiCom runtime. This does not
 * quiesce SQLite, preserve originals, certify a damaged database as healthy,
 * authenticate its caller, or authorize recovery. A file modified while
 * being hashed can escape detection; the real recovery core must stop writers
 * and independently preserve/verify the entire original file set.
 */
final class KiComPamOriginalInventory
{
    private const MAX_BYTES = 2147483648;

    private static function deny(string $code): array
    {
        return ['ok' => false, 'code' => $code, 'inspection_only' => true,
            'restore_permitted' => false];
    }

    public static function capture(): array
    {
        if (!function_exists('kicomSqliteFile')) {
            return self::deny('TRUSTED_SQLITE_PATH_UNAVAILABLE');
        }
        $path = kicomSqliteFile();
        if (!is_string($path) || $path === '' || is_link($path)
            || basename($path) !== 'kicom.sqlite') {
            return self::deny('TRUSTED_SQLITE_PATH_INVALID');
        }
        $parent = realpath(dirname($path));
        if ($parent === false || $parent !== dirname($path) || is_link(dirname($path))) {
            return self::deny('SQLITE_PARENT_UNTRUSTED');
        }
        $result = [];
        foreach (['db' => '', 'wal' => '-wal', 'shm' => '-shm'] as $key => $suffix) {
            $file = $path . $suffix;
            clearstatcache(true, $file);
            if (!file_exists($file) && !is_link($file)) {
                if ($key === 'db') return self::deny('ORIGINAL_DB_MISSING');
                $result[$key] = ['present' => false];
                continue;
            }
            if (is_link($file) || !is_file($file)
                || realpath($file) !== $parent . '/kicom.sqlite' . $suffix) {
                return self::deny('ORIGINAL_FILE_UNTRUSTED_' . strtoupper($key));
            }
            $stream = @fopen($file, 'rb');
            if ($stream === false) return self::deny('ORIGINAL_FILE_UNREADABLE_' . strtoupper($key));
            try {
                $start = fstat($stream);
                if (!is_array($start) || $start['size'] < 0
                    || $start['size'] > self::MAX_BYTES) {
                    return self::deny('ORIGINAL_FILE_SIZE_INVALID_' . strtoupper($key));
                }
                $hash = hash_init('sha256');
                $read = hash_update_stream($hash, $stream);
                $digest = hash_final($hash);
                $end = fstat($stream);
                clearstatcache(true, $file);
                $fromPath = @lstat($file);
                if (!is_array($end) || !is_array($fromPath) || $read !== $start['size']
                    || $start['size'] !== $end['size']
                    || $start['ino'] !== $end['ino']
                    || $start['mtime'] !== $end['mtime']
                    || $end['ino'] !== $fromPath['ino']
                    || $end['size'] !== $fromPath['size']
                    || $end['mtime'] !== $fromPath['mtime']
                    || is_link($file)) {
                    return self::deny('ORIGINAL_FILE_CHANGED_DURING_CAPTURE_' . strtoupper($key));
                }
                $result[$key] = ['present' => true,
                    'bytes' => $read, 'sha256' => $digest];
            } finally {
                fclose($stream);
            }
        }
        return ['ok' => true, 'code' => 'ORIGINAL_READ_ONLY_FINGERPRINT',
            'files' => $result, 'inspection_only' => true,
            'restore_permitted' => false];
    }

    public static function unchanged(array $earlier, array $later): array
    {
        if (empty($earlier['ok']) || empty($later['ok'])
            || !isset($earlier['files'], $later['files'])
            || array_keys($earlier['files']) !== ['db','wal','shm']
            || array_keys($later['files']) !== ['db','wal','shm']) {
            return self::deny('ORIGINAL_FINGERPRINT_INCOMPLETE');
        }
        foreach (['db', 'wal', 'shm'] as $kind) {
            if ($earlier['files'][$kind] !== $later['files'][$kind]) {
                return self::deny('ORIGINAL_FILESET_CHANGED_' . strtoupper($kind));
            }
        }
        return ['ok' => true, 'code' => 'ORIGINAL_FINGERPRINTS_MATCH',
            'inspection_only' => true, 'restore_permitted' => false];
    }
}
