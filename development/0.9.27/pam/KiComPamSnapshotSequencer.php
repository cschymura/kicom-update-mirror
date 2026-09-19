<?php
declare(strict_types=1);

/**
 * KiCom PAM 0.9.27 — ISOLATED DEVELOPMENT snapshot sequence journal.
 *
 * All snapshot creators would have to use this same wrapper/lock in a future
 * reviewed runtime integration. A legacy or out-of-band snapshot is NOT
 * silently adopted into this journal, and this class NEVER restores a DB.
 * Do not expose this class through an HTTP, KCL or untrusted callback gateway.
 */
final class KiComPamSnapshotSequencer
{
    private string $root;
    private string $journal;
    private string $lockFile;

    public function __construct(string $trustedSnapshotDir)
    {
        $root = realpath($trustedSnapshotDir);
        if ($root === false || !is_dir($root) || is_link($trustedSnapshotDir)) {
            throw new InvalidArgumentException('Trusted snapshot directory unavailable');
        }
        $this->root = $root;
        $this->journal = $root . '/pam-sequence';
        $this->lockFile = $root . '/pam-sequence.lock';
        if (!is_dir($this->journal) && !mkdir($this->journal, 0700) && !is_dir($this->journal)) {
            throw new RuntimeException('Sequence directory cannot be created');
        }
        if (is_link($this->journal) || realpath($this->journal) !== $this->journal) {
            throw new RuntimeException('Untrusted sequence directory');
        }
    }

    private function rows(): array
    {
        $files = glob($this->journal . '/entry-*.json');
        if (!is_array($files) || count($files) > 10000) {
            throw new RuntimeException('Sequence inventory unavailable or unbounded');
        }
        sort($files, SORT_STRING);
        $rows = [];
        $expected = 1;
        $prior = str_repeat('0', 64);
        $ids = [];
        foreach ($files as $path) {
            if (!preg_match('/^entry-([0-9]{10})\.json$/D', basename($path), $m)
                || (int)$m[1] !== $expected
                || is_link($path) || !is_file($path)
                || filesize($path) === false || filesize($path) > 4096) {
                throw new RuntimeException('Non-contiguous or untrusted sequence entry');
            }
            try {
                $row = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new RuntimeException('Unreadable sequence entry', 0, $e);
            }
            if (!is_array($row) || ($row['sequence'] ?? null) !== $expected
                || ($row['prev_entry_sha256'] ?? null) !== $prior
                || !is_string($row['snapshot_id'] ?? null)
                || !preg_match('/^[0-9]{14}-[a-f0-9]{10}$/D', $row['snapshot_id'])
                || isset($ids[$row['snapshot_id']])
                || !is_string($row['snapshot_sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $row['snapshot_sha256'])
                || !is_string($row['manifest_sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $row['manifest_sha256'])) {
                throw new RuntimeException('Sequence entry inconsistent');
            }
            $ids[$row['snapshot_id']] = true;
            $prior = hash('sha256', (string) file_get_contents($path));
            $rows[] = $row;
            $expected++;
        }
        return $rows;
    }

    /**
     * Requires trusted native snapshot writer. This lock must also cover every
     * future automatic and manual snapshot producer; otherwise inventory
     * reconciliation must fail closed on unsequenced files.
     */
    public function create(callable $trustedWriter): array
    {
        $lock = @fopen($this->lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Snapshot sequence lock unavailable');
        }
        try {
            $rows = $this->rows();
            // A new sequence must not be started on top of legacy files, a
            // previous interrupted native write or an out-of-band writer.
            // Reject BEFORE executing the new native writer.
            $metaInventory = glob($this->root . '/snapshot-*.json');
            $backupInventory = glob($this->root . '/snapshot-*.sqlite');
            if (!is_array($metaInventory) || !is_array($backupInventory)
                || count($metaInventory) !== count($rows)
                || count($backupInventory) !== count($rows)) {
                return ['ok' => false, 'code' => 'SNAPSHOT_LEGACY_INVENTORY_REQUIRES_REVIEW'];
            }
            if ($rows !== [] && empty($this->inspect()['ok'])) {
                return ['ok' => false, 'code' => 'SNAPSHOT_PRIOR_SEQUENCE_UNVERIFIED'];
            }
            $native = $trustedWriter();
            if (!is_array($native) || empty($native['ok'])
                || !is_string($native['id'] ?? null)
                || !preg_match('/^[0-9]{14}-[a-f0-9]{10}$/D', $native['id'])
                || !is_string($native['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $native['sha256'])) {
                return ['ok' => false, 'code' => 'SNAPSHOT_NATIVE_CREATE_FAILED'];
            }
            $id = $native['id'];
            $file = $this->root . '/snapshot-' . $id . '.sqlite';
            $manifest = $this->root . '/snapshot-' . $id . '.json';
            if (is_link($file) || is_link($manifest) || !is_file($file) || !is_file($manifest)
                || hash_file('sha256', $file) !== $native['sha256']) {
                return ['ok' => false, 'code' => 'SNAPSHOT_NATIVE_ARTIFACT_UNVERIFIED'];
            }
            $metaRaw = file_get_contents($manifest);
            if (!is_string($metaRaw)) {
                return ['ok' => false, 'code' => 'SNAPSHOT_NATIVE_MANIFEST_UNREADABLE'];
            }
            try {
                $meta = json_decode($metaRaw, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                return ['ok' => false, 'code' => 'SNAPSHOT_NATIVE_MANIFEST_INVALID'];
            }
            if (!is_array($meta) || ($meta['id'] ?? null) !== $id
                || ($meta['file_name'] ?? null) !== 'snapshot-' . $id . '.sqlite'
                || ($meta['sha256'] ?? null) !== $native['sha256']) {
                return ['ok' => false, 'code' => 'SNAPSHOT_NATIVE_MANIFEST_MISMATCH'];
            }
            if (function_exists('kicomSqliteVerifyFile')
                && empty(kicomSqliteVerifyFile($file, true)['ok'])) {
                return ['ok' => false, 'code' => 'SNAPSHOT_NATIVE_INTEGRITY_FAILED'];
            }
            foreach ($rows as $previous) {
                if ($previous['snapshot_id'] === $id) {
                    return ['ok' => false, 'code' => 'SNAPSHOT_ALREADY_SEQUENCED'];
                }
            }
            // The trusted writer may have created another backup outside this
            // lock, or crashed after publishing a second manifest. Never
            // append a seemingly clean ledger entry in that condition.
            $postMetas = glob($this->root . '/snapshot-*.json');
            $postBackups = glob($this->root . '/snapshot-*.sqlite');
            if (!is_array($postMetas) || !is_array($postBackups)
                || count($postMetas) !== count($rows) + 1
                || count($postBackups) !== count($rows) + 1) {
                return ['ok' => false, 'code' => 'SNAPSHOT_NATIVE_INVENTORY_DIVERGED'];
            }
            $number = count($rows) + 1;
            $previousFile = $number > 1
                ? $this->journal . '/entry-' . sprintf('%010d', $number - 1) . '.json'
                : null;
            $row = [
                'sequence' => $number,
                'snapshot_id' => $id,
                'snapshot_sha256' => $native['sha256'],
                'manifest_sha256' => hash('sha256', $metaRaw),
                'prev_entry_sha256' => $previousFile === null
                    ? str_repeat('0', 64) : hash_file('sha256', $previousFile)
            ];
            $destination = $this->journal . '/entry-' . sprintf('%010d', $number) . '.json';
            $encoded = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            // Exclusive create: any conflict is preserved and reported, not replaced.
            $handle = @fopen($destination, 'x');
            if ($handle === false) {
                throw new RuntimeException('Sequence entry already exists');
            }
            try {
                if (fwrite($handle, $encoded) !== strlen($encoded)
                    || !fflush($handle)
                    || !function_exists('fsync')
                    || !fsync($handle)) {
                    // A failed write is deliberately left as an invalid
                    // partial entry and cannot be silently replayed.
                    throw new RuntimeException('Sequence entry durable write unverified');
                }
            } finally {
                fclose($handle);
            }
            @chmod($destination, 0600);
            return ['ok' => true, 'code' => 'SNAPSHOT_SEQUENCED',
                'sequence' => $number, 'snapshot_id' => $id,
                'snapshot_sha256' => $native['sha256']];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Read-only reconciliation against every published native snapshot. Does
     * not consider a sequence to prove integrity unless exact on-disk bytes
     * and manifest hashes match the journal. Out-of-band files fail closed.
     */
    public function inspect(): array
    {
        try {
            $rows = $this->rows();
            $files = glob($this->root . '/snapshot-*.json');
            if (!is_array($files) || count($files) > 10000) {
                return ['ok' => false, 'code' => 'NATIVE_SNAPSHOT_INVENTORY_INVALID'];
            }
            if (count($files) !== count($rows)) {
                return ['ok' => false, 'code' => 'UNSEQUENCED_NATIVE_SNAPSHOT'];
            }
            foreach ($rows as $row) {
                $id = $row['snapshot_id'];
                $manifest = $this->root . '/snapshot-' . $id . '.json';
                $file = $this->root . '/snapshot-' . $id . '.sqlite';
                if (is_link($manifest) || is_link($file)
                    || !is_file($manifest) || !is_file($file)
                    || hash_file('sha256', $manifest) !== $row['manifest_sha256']
                    || hash_file('sha256', $file) !== $row['snapshot_sha256']
                    || (function_exists('kicomSqliteVerifyFile')
                        && empty(kicomSqliteVerifyFile($file, true)['ok']))) {
                    return ['ok' => false, 'code' => 'SEQUENCED_SNAPSHOT_VERIFICATION_FAILED'];
                }
            }
            if (!$rows) {
                return ['ok' => false, 'code' => 'NO_SEQUENCED_SNAPSHOTS'];
            }
            $latest = $rows[count($rows) - 1];
            return ['ok' => true, 'code' => 'SEQUENCED_SNAPSHOT_IDENTIFIED',
                'sequence' => $latest['sequence'],
                'snapshot_id' => $latest['snapshot_id'],
                'snapshot_sha256' => $latest['snapshot_sha256'],
                'inspection_only' => true, 'restore_permitted' => false];
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'SEQUENCE_LEDGER_UNTRUSTED'];
        }
    }
}
