<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramStore.php';

/**
 * Synthetic DEV-only RAID-1-inspired immutable snapshots.
 * One consistent source snapshot is byte-copied into two privately
 * provisioned directories; an independently anchored SHA-256 digest of the
 * third-directory manifest is REQUIRED before inspecting or restoring.
 *
 * This is not physical RAID, not an online transaction replica, and NOT a
 * production endpoint. A shared account/UID/host can corrupt both mirrors.
 * No secrets, live private engrams or actual memory are sent to GitHub.
 */
final class KiComEngramMirrorSet
{
    private string $first;
    private string $second;
    private string $manifestDir;
    private string $webroot;

    public function __construct(
        string $first, string $second, string $manifestDir, string $webroot
    ) {
        $web = realpath($webroot);
        if ($web === false || !is_dir($web)) {
            throw new RuntimeException('Reviewed webroot missing');
        }
        $this->webroot = $web;
        $this->first = self::privateDirectory($first, $web);
        $this->second = self::privateDirectory($second, $web);
        $this->manifestDir = self::privateDirectory($manifestDir, $web);
        $dirs = [$this->first, $this->second, $this->manifestDir];
        for ($i = 0; $i < 3; $i++) {
            for ($j = $i + 1; $j < 3; $j++) {
                if ($dirs[$i] === $dirs[$j]
                    || str_starts_with($dirs[$i], $dirs[$j] . DIRECTORY_SEPARATOR)
                    || str_starts_with($dirs[$j], $dirs[$i] . DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException('Mirror and manifest directories must be distinct peers');
                }
            }
        }
    }

    private static function privateDirectory(string $path, string $web): string
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || is_link($path)) {
            throw new RuntimeException('Private path must be absolute and non-symlink');
        }
        $cursor = $path;
        while (true) {
            if (is_link($cursor)) throw new RuntimeException('Symlink path component refused');
            $parent = dirname($cursor);
            if ($parent === $cursor) break;
            $cursor = $parent;
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real)
            || $real === $web || str_starts_with($real, $web . DIRECTORY_SEPARATOR)
            || str_starts_with($web, $real . DIRECTORY_SEPARATOR)
            || (fileperms($real) & 0077) !== 0) {
            throw new RuntimeException('Private directory exposure or permissions invalid');
        }
        return $real;
    }

    private static function privateFile(string $path): bool
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) return false;
        $stat = @stat($path);
        return $stat !== false && $stat['nlink'] === 1
            && ($stat['mode'] & 0077) === 0;
    }

    private static function digestFile(string $path): ?string
    {
        if (!self::privateFile($path)) return null;
        $sha = @hash_file('sha256', $path);
        return $sha === false ? null : $sha;
    }

    private static function putExclusive(string $path, string $bytes): void
    {
        $umask = umask(0077);
        try {
            $out = @fopen($path, 'x+b');
            if ($out === false) throw new RuntimeException('Immutable file already exists');
            try {
                if (!@chmod($path, 0600) || fwrite($out, $bytes) !== strlen($bytes)
                    || !fflush($out)) {
                    throw new RuntimeException('Immutable manifest write failed');
                }
            } finally {
                fclose($out);
            }
        } finally {
            umask($umask);
        }
    }

    /**
     * Returns ONLY a manifest filename and digest; caller must independently
     * preserve/anchor the digest outside all three writable mirror directories.
     * Uncommitted orphan files are kept for operator quarantine on failure.
     * NEVER select snapshots by filename/date alone.
     */
    public function capture(KiComEngramStore $source): array
    {
        $snapshot = $source->backup($this->first, $this->webroot);
        $name = $snapshot['filename'];
        if (!preg_match('/\Aengram-[a-f0-9]{32}\.sqlite\z/D', $name)) {
            throw new RuntimeException('Unexpected snapshot filename');
        }
        $firstFile = $this->first . DIRECTORY_SEPARATOR . $name;
        $secondFile = $this->second . DIRECTORY_SEPARATOR . $name;
        if (!hash_equals($snapshot['sha256'], (string)self::digestFile($firstFile))) {
            throw new RuntimeException('Initial snapshot checksum mismatch');
        }
        $umask = umask(0077);
        try {
            $input = @fopen($firstFile, 'rb');
            $output = @fopen($secondFile, 'x+b');
            if ($input === false || $output === false) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('Mirror snapshot copy failed');
            }
            try {
                $copied = stream_copy_to_stream($input, $output);
                if ($copied === false || !fflush($output)
                    || !@chmod($secondFile, 0600)) {
                    throw new RuntimeException('Mirror copy write failed');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
        } finally {
            umask($umask);
        }
        if (!hash_equals($snapshot['sha256'], (string)self::digestFile($secondFile))) {
            throw new RuntimeException('Mirror snapshot digest mismatch');
        }
        $generation = bin2hex(random_bytes(16));
        $manifest = [
            'format' => 'engram-mirror-v1',
            'generation' => $generation,
            'snapshot' => $name,
            'snapshot_sha256' => $snapshot['sha256'],
            'revision_count' => $snapshot['revision_count'],
        ];
        $bytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $manifestName = 'mirror-' . $generation . '.json';
        self::putExclusive($this->manifestDir . DIRECTORY_SEPARATOR . $manifestName, $bytes);
        return [
            'manifest' => $manifestName,
            'manifest_sha256' => hash('sha256', $bytes),
            'revision_count' => $snapshot['revision_count'],
            'mirrors_created' => 2,
            'independent_manifest_anchor_stored' => false,
        ];
    }

    /**
     * The supplied digest is operator-trusted independently of the mirror set;
     * never accept a digest read from the same untrusted storage as authority.
     * Returns only diagnostics, not snapshot bytes or private paths.
     */
    public function inspect(string $manifestName, string $trustedDigest): array
    {
        if (!preg_match('/\Amirror-[a-f0-9]{32}\.json\z/D', $manifestName)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $trustedDigest)) {
            throw new RuntimeException('Manifest identity invalid');
        }
        $path = $this->manifestDir . DIRECTORY_SEPARATOR . $manifestName;
        $actual = self::digestFile($path);
        if ($actual === null || !hash_equals($trustedDigest, $actual)) {
            throw new RuntimeException('Independent trusted manifest digest mismatch');
        }
        $m = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($m) || array_keys($m) !== [
                'format','generation','snapshot','snapshot_sha256','revision_count'
            ]
            || $m['format'] !== 'engram-mirror-v1'
            || $manifestName !== 'mirror-' . $m['generation'] . '.json'
            || !preg_match('/\A[a-f0-9]{32}\z/D', $m['generation'])
            || !preg_match('/\Aengram-[a-f0-9]{32}\.sqlite\z/D', $m['snapshot'])
            || !preg_match('/\A[a-f0-9]{64}\z/D', $m['snapshot_sha256'])
            || !is_int($m['revision_count']) || $m['revision_count'] < 0) {
            throw new RuntimeException('Manifest schema or version invalid');
        }
        $good = [];
        foreach ([$this->first, $this->second] as $index => $dir) {
            $hash = self::digestFile($dir . DIRECTORY_SEPARATOR . $m['snapshot']);
            if ($hash !== null && hash_equals($m['snapshot_sha256'], $hash)) {
                $good[] = $index;
            }
        }
        return [
            'state' => count($good) === 2 ? 'mirrored'
                : (count($good) === 1 ? 'degraded' : 'unrecoverable'),
            'verified_mirrors' => count($good),
            'revision_count' => $m['revision_count'],
            'source_indexes' => $good,
            'snapshot' => $m['snapshot'],
            'snapshot_sha256' => $m['snapshot_sha256'],
        ];
    }

    /**
     * Recover into an already provisioned EMPTY directory; does not alter
     * live database, mirror files, damaged copy, or historical generation.
     */
    public function recover(
        string $manifestName, string $trustedDigest, string $emptyPrivateDirectory
    ): array {
        $status = $this->inspect($manifestName, $trustedDigest);
        if ($status['verified_mirrors'] < 1) {
            throw new RuntimeException('No independently verified snapshot available');
        }
        $destination = self::privateDirectory($emptyPrivateDirectory, $this->webroot);
        foreach ([$this->first, $this->second, $this->manifestDir] as $protected) {
            if ($destination === $protected
                || str_starts_with($destination, $protected . DIRECTORY_SEPARATOR)
                || str_starts_with($protected, $destination . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Restore cannot target a mirror or manifest directory');
            }
        }
        $dirs = [$this->first, $this->second];
        foreach ($status['source_indexes'] as $index) {
            try {
                $result = KiComEngramStore::restore(
                    $dirs[$index] . DIRECTORY_SEPARATOR . $status['snapshot'],
                    $destination, $this->webroot, $status['snapshot_sha256']
                );
                if ($result['revision_count'] !== $status['revision_count']) {
                    throw new RuntimeException('Manifest revision count disagrees with snapshot');
                }
                return ['restored' => true, 'source_mirror' => $index,
                    'previous_state' => $status['state'],
                    'revision_count' => $result['revision_count']];
            } catch (Throwable $e) {
                // Restoration never overwrites an existing DB. Stop if the
                // destination was touched, rather than silently use next copy.
                if (file_exists($destination . '/engrams.sqlite')
                    || is_link($destination . '/engrams.sqlite')) {
                    throw new RuntimeException('Restore destination needs operator review');
                }
            }
        }
        throw new RuntimeException('No verified mirror could be restored');
    }
}
