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

    /** Revalidate permissions and symlink components on EVERY mirror operation. */
    private function assertStorageTopology(): void
    {
        clearstatcache(true);
        foreach ([$this->first, $this->second, $this->manifestDir] as $dir) {
            if (self::privateDirectory($dir, $this->webroot) !== $dir) {
                throw new RuntimeException('Private mirror topology changed');
            }
        }
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
        $this->assertStorageTopology();
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
     * A NEW immutable generation from an independently anchored, degraded v1/v2
     * generation. Never rewrite the damaged copy or choose by timestamp.
     * The caller must separately anchor the returned NEW manifest digest.
     *
     * This only reconstructs an offline snapshot, never changes the live DB.
     * On copy failure leave new uncommitted files for operator quarantine.
     */
    public function rebuildNewGeneration(
        string $parentManifest, string $trustedParentDigest, ?callable $devFaultHook = null
    ): array {
        // DEV-only deterministic interruption fixture; callback receives only a
        // constant phase label, NEVER a private path or memory content.
        $fault = static function (string $phase) use ($devFaultHook): void {
            if ($devFaultHook !== null) { $devFaultHook($phase); }
        };
        // Advisory, local-filesystem parent-generation lease: never use a
        // mutable new lock file that could accidentally become an orphan.
        // SIGKILL releases the kernel flock; retry still needs the externally
        // trusted manifest digest. No cross-host/distributed guarantee.
        $this->assertStorageTopology();
        if (!preg_match('/\\Amirror-[a-f0-9]{32}\\.json\\z/D', $parentManifest)) {
            throw new RuntimeException('Repair parent manifest name invalid');
        }
        $lockPath = $this->manifestDir . DIRECTORY_SEPARATOR . $parentManifest;
        if (!self::privateFile($lockPath)) {
            throw new RuntimeException('Repair parent manifest is not a private regular file');
        }
        $parentHandle = @fopen($lockPath, 'rb');
        if ($parentHandle === false) {
            throw new RuntimeException('Repair parent manifest cannot be opened');
        }
        if (!@flock($parentHandle, LOCK_EX | LOCK_NB)) {
            fclose($parentHandle);
            throw new RuntimeException('Concurrent repair of this parent is already active');
        }
        try {
        $parent = $this->inspect($parentManifest, $trustedParentDigest);
        if ($parent['state'] !== 'degraded' || $parent['verified_mirrors'] !== 1
            || count($parent['source_indexes']) !== 1) {
            throw new RuntimeException('Repair requires exactly one anchored intact parent mirror');
        }
        $sourceIndex = $parent['source_indexes'][0];
        $sourceDirectory = [$this->first, $this->second][$sourceIndex];
        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $parent['snapshot'];
        if (!hash_equals($parent['snapshot_sha256'], (string)self::digestFile($sourceFile))) {
            throw new RuntimeException('Parent snapshot changed before repair');
        }
        $generation = bin2hex(random_bytes(16));
        $newSnapshot = 'engram-' . $generation . '.sqlite';
        $newManifest = 'mirror-' . $generation . '.json';
        foreach ([$this->first, $this->second] as $dir) {
            $dest = $dir . DIRECTORY_SEPARATOR . $newSnapshot;
            if (file_exists($dest) || is_link($dest)) {
                throw new RuntimeException('Repair snapshot name already exists');
            }
        }
        $manifestPath = $this->manifestDir . DIRECTORY_SEPARATOR . $newManifest;
        if (file_exists($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('Repair manifest name already exists');
        }
        // Copy from exactly the same verified parent snapshot, not two
        // independent database backups or a currently changing live WAL.
        $fault('before-first-copy');
        foreach ([$this->first, $this->second] as $index => $dir) {
            self::copyPrivateSnapshot(
                $sourceFile, $dir . DIRECTORY_SEPARATOR . $newSnapshot,
                $parent['snapshot_sha256'],
                static function () use ($fault, $index): void {
                    $fault($index === 0 ? 'during-first-copy' : 'during-second-copy');
                }
            );
            $fault($index === 0 ? 'after-first-copy' : 'after-second-copy');
        }
        // Re-check source AND both destination copies immediately before
        // publishing a complete manifest; an incomplete generation lacks it.
        if (!hash_equals($parent['snapshot_sha256'], (string)self::digestFile($sourceFile))) {
            throw new RuntimeException('Parent snapshot changed during repair');
        }
        foreach ([$this->first, $this->second] as $dir) {
            if (!hash_equals($parent['snapshot_sha256'],
                (string)self::digestFile($dir . DIRECTORY_SEPARATOR . $newSnapshot))) {
                throw new RuntimeException('Repair snapshot verification failed');
            }
        }
        $manifest = [
            'format' => 'engram-mirror-v2',
            'generation' => $generation,
            'snapshot' => $newSnapshot,
            'snapshot_sha256' => $parent['snapshot_sha256'],
            'revision_count' => $parent['revision_count'],
            'parent_manifest' => $parentManifest,
            'parent_manifest_sha256' => $trustedParentDigest,
            'parent_source_mirror' => $sourceIndex,
        ];
        $bytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $fault('before-manifest-publish');
        self::putExclusive($manifestPath, $bytes);
        $fault('after-manifest-publish');
        return [
            'manifest' => $newManifest,
            'manifest_sha256' => hash('sha256', $bytes),
            'revision_count' => $parent['revision_count'],
            'previous_state' => 'degraded',
            'mirrors_created' => 2,
            'independent_manifest_anchor_stored' => false,
        ];
        } finally {
            @flock($parentHandle, LOCK_UN);
            fclose($parentHandle);
        }
    }

    /**
     * Exclusive bounded copy. Never touches an existing destination. Partially
     * written output is retained for quarantine, never advertised via manifest.
     */
    private static function copyPrivateSnapshot(
        string $source, string $destination, string $expectedSha256,
        ?callable $firstChunkHook = null
    ): void {
        if (!hash_equals($expectedSha256, (string)self::digestFile($source))) {
            throw new RuntimeException('Snapshot source integrity failed');
        }
        $bytes = filesize($source);
        if ($bytes === false || $bytes < 1 || $bytes > 536870912) {
            throw new RuntimeException('Snapshot outside DEV bounded size');
        }
        $mask = umask(0077);
        try {
            $input = @fopen($source, 'rb');
            $output = @fopen($destination, 'x+b');
            if ($input === false || $output === false) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('Exclusive repair copy failed to start');
            }
            try {
                if (!@chmod($destination, 0600)) {
                    throw new RuntimeException('Exclusive repair copy permissions invalid');
                }
                $copied = 0;
                while ($copied < $bytes) {
                    $chunk = fread($input, min(512, $bytes - $copied));
                    if (!is_string($chunk) || $chunk === '') {
                        throw new RuntimeException('Exclusive repair source read failed');
                    }
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Exclusive repair partial write failed');
                    }
                    $copied += strlen($chunk);
                    if ($copied === strlen($chunk) && $copied < $bytes
                        && $firstChunkHook !== null) {
                        // First 512 bytes have been flushed but the complete
                        // file is not written. Deterministic synthetic tests
                        // can now SIGKILL this separate process mid-copy.
                        if (!fflush($output)) {
                            throw new RuntimeException('Exclusive repair partial flush failed');
                        }
                        $firstChunkHook();
                    }
                }
                if ($copied !== $bytes || fread($input, 1) !== ''
                    || !fflush($output)) {
                    throw new RuntimeException('Exclusive repair copy length or flush mismatch');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
        } finally {
            umask($mask);
        }
        if (!hash_equals($expectedSha256, (string)self::digestFile($destination))) {
            throw new RuntimeException('Exclusive repair copy hash mismatch');
        }
    }

    /**
     * The supplied digest is operator-trusted independently of the mirror set;
     * never accept a digest read from the same untrusted storage as authority.
     * Returns only diagnostics, not snapshot bytes or private paths.
     */
    public function inspect(string $manifestName, string $trustedDigest): array
    {
        $this->assertStorageTopology();
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
        $baseKeys = ['format','generation','snapshot','snapshot_sha256','revision_count'];
        $v2Keys = array_merge($baseKeys, [
            'parent_manifest','parent_manifest_sha256','parent_source_mirror'
        ]);
        if (!is_array($m)
            || !in_array($m['format'] ?? null, ['engram-mirror-v1','engram-mirror-v2'], true)
            || array_keys($m) !== ($m['format'] === 'engram-mirror-v1' ? $baseKeys : $v2Keys)
            || ($m['format'] === 'engram-mirror-v2' && (
                !is_string($m['parent_manifest'])
                || !preg_match('/\Amirror-[a-f0-9]{32}\.json\z/D', $m['parent_manifest'])
                || !is_string($m['parent_manifest_sha256'])
                || !preg_match('/\A[a-f0-9]{64}\z/D', $m['parent_manifest_sha256'])
                || !in_array($m['parent_source_mirror'], [0,1], true)
                || $m['parent_manifest'] === $manifestName
            ))
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
            'manifest_format' => $m['format'],
            'parent_manifest' => $m['format'] === 'engram-mirror-v2'
                ? $m['parent_manifest'] : null,
            'parent_manifest_sha256' => $m['format'] === 'engram-mirror-v2'
                ? $m['parent_manifest_sha256'] : null,
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
     * A bounded READ-ONLY orphan inventory. Caller supplies an independently
     * trusted, complete inventory of anchored manifest/digest pairs. A missing
     * item is listed as unanchored, NEVER auto-selected for recovery or deleted.
     * Counts only; no filenames, absolute paths or memory bytes in the result.
     *
     * This is an explicit operator-review helper, not a retention/purge policy.
     */
    public function inventoryUnanchoredArtifacts(array $trustedAnchors): array
    {
        $this->assertStorageTopology();
        if (count($trustedAnchors) > 256) {
            throw new RuntimeException('Anchor inventory exceeds DEV bound');
        }
        $knownManifests = [];
        $knownSnapshots = [];
        foreach ($trustedAnchors as $anchor) {
            if (!is_array($anchor) || array_keys($anchor) !== ['manifest', 'sha256']
                || !is_string($anchor['manifest']) || !is_string($anchor['sha256'])) {
                throw new RuntimeException('Independent anchor inventory malformed');
            }
            if (isset($knownManifests[$anchor['manifest']])) {
                throw new RuntimeException('Duplicate independently trusted manifest');
            }
            $verified = $this->inspect($anchor['manifest'], $anchor['sha256']);
            $knownManifests[$anchor['manifest']] = true;
            $knownSnapshots[$verified['snapshot']] = true;
        }
        $unknownManifests = 0;
        $unknownMirrorFiles = 0;
        foreach ([$this->manifestDir, $this->first, $this->second] as $index => $directory) {
            $entries = scandir($directory);
            if ($entries === false || count($entries) > 10000) {
                throw new RuntimeException('Orphan directory scan unavailable or exceeds DEV bound');
            }
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') continue;
                if ($index === 0) {
                    if (!isset($knownManifests[$name])) ++$unknownManifests;
                } elseif (!isset($knownSnapshots[$name])) {
                    ++$unknownMirrorFiles;
                }
            }
        }
        return [
            'known_generations' => count($knownManifests),
            'unanchored_manifest_files' => $unknownManifests,
            'unanchored_mirror_files' => $unknownMirrorFiles,
            'operator_review_required' => $unknownManifests + $unknownMirrorFiles > 0,
            'auto_recovery_permitted' => false,
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
        if (scandir($destination) !== ['.', '..']) {
            throw new RuntimeException('Restore destination must be completely empty');
        }
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
