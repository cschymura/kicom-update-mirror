<?php
declare(strict_types=1);

/**
 * DEV-only internal filesystem probe: NOT a route, authenticator, CLI command,
 * installer or permit to ingest real memories. Invoke only from the existing
 * identity-verified KiCom DEV capability router after explicit allowlisting.
 * This class deliberately returns no absolute paths or private file contents.
 */
final class KiComEngramPrivatePathProbe
{
    private static function checkDirectory(string $path, string $webRoot): string
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || is_link($path)) {
            throw new RuntimeException('Private directory must be an absolute non-symlink path');
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('Private directory missing');
        }
        $cursor = $path;
        while (true) {
            if (is_link($cursor)) {
                throw new RuntimeException('Private path contains symlink');
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) { break; }
            $cursor = $parent;
        }
        if ($real === $webRoot || str_starts_with($real, $webRoot . DIRECTORY_SEPARATOR)
            || str_starts_with($webRoot, $real . DIRECTORY_SEPARATOR)
            || (fileperms($real) & 0077) !== 0) {
            throw new RuntimeException('Private directory isolation or mode failed');
        }
        return $real;
    }

    private static function syntheticReadWrite(string $directory): void
    {
        $filename = $directory . DIRECTORY_SEPARATOR . '.engram-probe-' . bin2hex(random_bytes(16));
        $payload = random_bytes(32);
        $handle = null;
        $oldMask = umask(0077);
        try {
            $handle = @fopen($filename, 'x+b');
            if ($handle === false) {
                throw new RuntimeException('Private test file cannot be created');
            }
            if (!@chmod($filename, 0600) || fwrite($handle, $payload) !== strlen($payload)
                || !fflush($handle) || fseek($handle, 0) !== 0
                || !hash_equals($payload, (string)fread($handle, strlen($payload)))) {
                throw new RuntimeException('Private read/write verification failed');
            }
            $st = @fstat($handle);
            if ($st === false || $st['nlink'] !== 1 || ($st['mode'] & 0077) !== 0) {
                throw new RuntimeException('Private file permissions invalid');
            }
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            if (is_file($filename) && !is_link($filename)) { @unlink($filename); }
            umask($oldMask);
        }
    }

    /**
     * The webRoot and directories must come from reviewed server configuration,
     * never from request parameters. A pass checks only this PHP process,
     * not other PHP SAPI identities, HTTP aliases or access from other hosts.
     */
    public static function run(string $dataDirectory, string $backupDirectory, string $webDocumentRoot): array
    {
        // Backwards-compatible isolated DEV test wrapper. The actual first-party
        // handler MUST use runAgainstWebRoots with the complete reviewed list.
        return self::runAgainstWebRoots($dataDirectory, $backupDirectory, [$webDocumentRoot]);
    }

    /**
     * Filesystem isolation against EVERY operator-reviewed vhost document root,
     * including provider/default-host roots where known. The caller supplies a
     * fixed server-side list; request parameters must NEVER control this list.
     * This cannot discover unlisted aliases or prove external HTTP non-exposure.
     */
    public static function runAgainstWebRoots(
        string $dataDirectory,
        string $backupDirectory,
        array $webDocumentRoots
    ): array {
        if (count($webDocumentRoots) < 1 || count($webDocumentRoots) > 32
            || array_keys($webDocumentRoots) !== range(0, count($webDocumentRoots) - 1)) {
            throw new RuntimeException('Reviewed webroot list missing or invalid');
        }
        $webRoots = [];
        foreach ($webDocumentRoots as $requestedRoot) {
            if (!is_string($requestedRoot) || $requestedRoot === ''
                || $requestedRoot[0] !== DIRECTORY_SEPARATOR) {
                throw new RuntimeException('Webroot must be an absolute path');
            }
            $cursor = $requestedRoot;
            while (true) {
                if (is_link($cursor)) {
                    throw new RuntimeException('Webroot path contains symlink');
                }
                $parent = dirname($cursor);
                if ($parent === $cursor) { break; }
                $cursor = $parent;
            }
            $root = realpath($requestedRoot);
            if ($root === false || !is_dir($root) || $root === DIRECTORY_SEPARATOR
                || in_array($root, $webRoots, true)) {
                throw new RuntimeException('Webroot is missing or duplicated');
            }
            $webRoots[] = $root;
        }
        // Preflight every webroot before any synthetic file creation. Check
        // the private parent as well as data and backup directories.
        $data = null;
        $backups = null;
        foreach ($webRoots as $webRoot) {
            $data = self::checkDirectory($dataDirectory, $webRoot);
            $backups = self::checkDirectory($backupDirectory, $webRoot);
            if ($data === $backups || dirname($data) !== dirname($backups)
                || basename($data) !== 'data' || basename($backups) !== 'backups') {
                throw new RuntimeException('Private data and backup directories are not isolated siblings');
            }
            self::checkDirectory(dirname($data), $webRoot);
        }
        self::syntheticReadWrite($data);
        self::syntheticReadWrite($backups);
        return ['private_paths_checked' => true, 'synthetic_rw_data' => true,
            'synthetic_rw_backups' => true, 'configured_webroots_checked' => count($webRoots),
            'public_http_exposure_verified' => false];
    }
}
