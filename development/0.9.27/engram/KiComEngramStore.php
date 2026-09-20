<?php
declare(strict_types=1);

/**
 * Isolated DEV-only engram store. NOT a network endpoint, an authenticator,
 * or a replacement for the existing KiCom KCL/DEV authorization boundaries.
 * Callers must supply the subject/namespace from verified server-side identity,
 * never from unauthenticated user request parameters.
 */
final class KiComEngramStore
{
    private PDO $db;
    private string $dbPath;

    public function __construct(string $privateDirectory, string $publicDocumentRoot)
    {
        if (is_link($privateDirectory) || !is_dir($privateDirectory)) {
            throw new RuntimeException('Private directory must exist and must not be a symlink');
        }
        $dir = realpath($privateDirectory);
        $web = realpath($publicDocumentRoot);
        if ($dir === false || $web === false || !is_dir($web)) {
            throw new RuntimeException('Private directory and document root must resolve');
        }
        if ($dir === $web || str_starts_with($dir, $web . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private engrams must remain outside the web document root');
        }
        if ((fileperms($dir) & 0077) !== 0) {
            throw new RuntimeException('Private directory requires mode 0700 or stricter');
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'engrams.sqlite';
        if (is_link($path)) {
            throw new RuntimeException('Symlink database forbidden');
        }
        if (file_exists($path)) {
            $s = stat($path);
            if (!is_file($path) || $s === false || $s['nlink'] !== 1 || ($s['mode'] & 0077) !== 0) {
                throw new RuntimeException('Database must be a private regular single-link file');
            }
        } else {
            $old = umask(0077);
            try {
                $handle = @fopen($path, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Cannot create private database');
                }
                fclose($handle);
                chmod($path, 0600);
            } finally {
                umask($old);
            }
        }
        $this->dbPath = $path;
        $this->assertPrivateStorageFiles();
        $oldOpenMask = umask(0077);
        try {
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = FULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS engram_revisions (
            subject TEXT NOT NULL,
            namespace TEXT NOT NULL,
            id TEXT NOT NULL,
            revision INTEGER NOT NULL CHECK(revision > 0),
            kind TEXT NOT NULL,
            body TEXT NOT NULL,
            source_kind TEXT NOT NULL,
            source_ref TEXT NOT NULL,
            entry_state TEXT NOT NULL CHECK(entry_state IN (\'active\',\'withdrawn\')),
            previous_hash TEXT NOT NULL,
            revision_hash TEXT NOT NULL,
            recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(subject, namespace, id, revision)
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS engram_lookup
            ON engram_revisions(subject, namespace, kind, id, revision)');
        $check = $this->db->query('PRAGMA quick_check')->fetchColumn();
        if ($check !== 'ok') {
            throw new RuntimeException('Engram SQLite quick_check failed');
        }
        $this->assertPrivateStorageFiles();
        } finally {
            umask($oldOpenMask);
        }
    }

    /** All SQLite main/WAL/SHM files are private, regular and single-link. */
    private function assertPrivateStorageFiles(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            clearstatcache(true, $file);
            if (is_link($file)) {
                throw new RuntimeException('Symlink in private SQLite storage denied');
            }
            if (!file_exists($file)) { continue; }
            $st = @stat($file);
            if ($st === false || !is_file($file) || $st['nlink'] !== 1
                || ($st['mode'] & 0077) !== 0) {
                throw new RuntimeException('SQLite storage file must be private and single-link');
            }
        }
    }

    private static function key(string $value): string
    {
        if (!preg_match('/\\A[a-z0-9][a-z0-9._:-]{0,63}\\z/D', $value)) {
            throw new InvalidArgumentException('Invalid subject/namespace/id key');
        }
        return $value;
    }

    private static function text(string $value, int $max): string
    {
        if ($value === '' || strlen($value) > $max || !preg_match('//u', $value)) {
            throw new InvalidArgumentException('Invalid or oversized Unicode text');
        }
        return $value;
    }

    private static function kind(string $kind): string
    {
        if (!in_array($kind, ['collaboration', 'decision', 'lesson', 'technical'], true)) {
            throw new InvalidArgumentException('Unknown engram kind');
        }
        return $kind;
    }

    private static function source(string $kind): string
    {
        if (!in_array($kind, ['explicit_user', 'approved_summary', 'verified_checkpoint', 'synthetic_test'], true)) {
            throw new InvalidArgumentException('Unapproved provenance');
        }
        return $kind;
    }

    private static function digest(array $row): string
    {
        $fields = ['subject','namespace','id','revision','kind','body','source_kind','source_ref','entry_state','previous_hash'];
        return hash('sha256', json_encode(array_map(static fn(string $name) => $row[$name], $fields), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Explicitly called by an already authorized local application. Never auto-ingest chat. */
    public function create(string $subject, string $namespace, string $kind, string $body, string $sourceKind, string $sourceRef): array
    {
        $this->assertPrivateStorageFiles();
        $subject = self::key($subject);
        $namespace = self::key($namespace);
        $kind = self::kind($kind);
        $body = self::text($body, 4096);
        $sourceKind = self::source($sourceKind);
        $sourceRef = self::text($sourceRef, 256);
        $row = [
            'subject' => $subject, 'namespace' => $namespace, 'id' => bin2hex(random_bytes(16)),
            'revision' => 1, 'kind' => $kind, 'body' => $body,
            'source_kind' => $sourceKind, 'source_ref' => $sourceRef,
            'entry_state' => 'active', 'previous_hash' => str_repeat('0', 64)
        ];
        $row['revision_hash'] = self::digest($row);
        $this->insert($row);
        return ['id' => $row['id'], 'revision' => 1, 'revision_hash' => $row['revision_hash']];
    }

    /**
     * Optimistic revision-gated change or withdrawal; past revisions are retained
     * in this DEV prototype. Do not store real personal data before a tested
     * deletion/retention path exists.
     */
    public function revise(string $subject, string $namespace, string $id, int $expectedRevision, string $expectedHash, string $body, string $sourceKind, string $sourceRef, bool $withdraw = false): array
    {
        $subject = self::key($subject);
        $namespace = self::key($namespace);
        $id = self::key($id);
        $sourceKind = self::source($sourceKind);
        $sourceRef = self::text($sourceRef, 256);
        $body = self::text($body, 4096);
        if ($expectedRevision < 1 || !preg_match('/\\A[a-f0-9]{64}\\z/D', $expectedHash)) {
            throw new InvalidArgumentException('Invalid revision precondition');
        }
        $this->assertPrivateStorageFiles();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q = $this->db->prepare('SELECT * FROM engram_revisions
                WHERE subject=? AND namespace=? AND id=? ORDER BY revision DESC LIMIT 1');
            $q->execute([$subject, $namespace, $id]);
            $previous = $q->fetch();
            if (!$previous || (int)$previous['revision'] !== $expectedRevision
                || !hash_equals($previous['revision_hash'], $expectedHash)
                || !hash_equals($previous['revision_hash'], self::digest($previous))
                || $previous['entry_state'] !== 'active') {
                throw new RuntimeException('Engram missing, altered, withdrawn or stale revision');
            }
            $row = [
                'subject' => $subject, 'namespace' => $namespace, 'id' => $id,
                'revision' => $expectedRevision + 1, 'kind' => $previous['kind'],
                'body' => $body, 'source_kind' => $sourceKind, 'source_ref' => $sourceRef,
                'entry_state' => $withdraw ? 'withdrawn' : 'active',
                'previous_hash' => $previous['revision_hash']
            ];
            $row['revision_hash'] = self::digest($row);
            $this->insert($row);
            $this->db->exec('COMMIT');
            return ['id' => $id, 'revision' => $row['revision'], 'revision_hash' => $row['revision_hash']];
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Caller must supply a previously verified subject and permitted namespace. */
    public function search(string $subject, string $namespace, string $needle, int $limit = 10): array
    {
        $subject = self::key($subject);
        $namespace = self::key($namespace);
        $needle = self::text($needle, 128);
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Search limit out of bounds');
        }
        $this->assertPrivateStorageFiles();
        $q = $this->db->prepare('SELECT r.id,r.revision,r.kind,r.body,r.source_kind,r.source_ref,r.revision_hash
            FROM engram_revisions r
            WHERE r.subject=:subject AND r.namespace=:namespace AND r.entry_state=\'active\'
            AND r.revision=(SELECT MAX(p.revision) FROM engram_revisions p
                WHERE p.subject=r.subject AND p.namespace=r.namespace AND p.id=r.id)
            AND instr(lower(r.body),lower(:needle)) > 0
            ORDER BY r.id LIMIT :max_rows');
        $q->bindValue(':subject', $subject, PDO::PARAM_STR);
        $q->bindValue(':namespace', $namespace, PDO::PARAM_STR);
        $q->bindValue(':needle', $needle, PDO::PARAM_STR);
        $q->bindValue(':max_rows', $limit, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll();
    }


    /**
     * DEV-only offline private snapshot. SQLite VACUUM INTO takes a consistent
     * transaction-level copy including committed WAL content; do NOT copy the
     * live .sqlite file alone. Backup files contain ALL historical revisions,
     * including withdrawn records, and must stay outside webroot.
     *
     * Return metadata only: never send the backup or its path to public logs.
     */
    public function backup(string $backupDirectory, string $publicDocumentRoot): array
    {
        $this->assertPrivateStorageFiles();
        $directory = self::checkedPrivateDirectory($backupDirectory, $publicDocumentRoot);
        $filename = 'engram-' . bin2hex(random_bytes(16)) . '.sqlite';
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Backup path already exists');
        }
        $old = umask(0077);
        try {
            $this->db->exec('VACUUM INTO ' . $this->db->quote($path));
            if (!is_file($path) || is_link($path) || !@chmod($path, 0600)) {
                throw new RuntimeException('Private backup creation failed');
            }
            $stat = stat($path);
            if ($stat === false || $stat['nlink'] !== 1 || ($stat['mode'] & 0077) !== 0) {
                throw new RuntimeException('Backup is not a single-link private regular file');
            }
            $snapshot = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($snapshot->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('Backup integrity check failed');
            }
            $rows = (int)$snapshot->query('SELECT COUNT(*) FROM engram_revisions')->fetchColumn();
            unset($snapshot);
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Backup digest failed');
            }
            return ['filename' => $filename, 'sha256' => $hash, 'revision_count' => $rows];
        } catch (Throwable $e) {
            // Failed incomplete snapshot only; never remove pre-existing backups.
            if (is_file($path) && !is_link($path)) {
                @unlink($path);
            }
            throw $e;
        } finally {
            umask($old);
        }
    }

    /**
     * Restore into an EMPTY, already-provisioned private directory only.
     * The expected SHA-256 MUST be supplied through a separate trusted
     * operator manifest; a checksum stored beside the backup is not authority.
     * This never overwrites a live database, WAL, SHM, or a previous restore.
     */
    public static function restore(
        string $backupPath,
        string $destinationDirectory,
        string $publicDocumentRoot,
        string $expectedSha256
    ): array {
        if (!preg_match('/\\A[a-f0-9]{64}\\z/D', $expectedSha256)) {
            throw new InvalidArgumentException('Invalid independent backup digest');
        }
        $sourceDirectory = self::checkedPrivateDirectory(dirname($backupPath), $publicDocumentRoot);
        $destination = self::checkedPrivateDirectory($destinationDirectory, $publicDocumentRoot);
        $source = $sourceDirectory . DIRECTORY_SEPARATOR . basename($backupPath);
        if (is_link($backupPath) || !is_file($backupPath) || realpath($backupPath) !== $source) {
            throw new RuntimeException('Backup path must be a private regular file');
        }
        $s = stat($source);
        if ($s === false || $s['nlink'] !== 1 || ($s['mode'] & 0077) !== 0) {
            throw new RuntimeException('Backup file permissions or links invalid');
        }
        if (!hash_equals($expectedSha256, (string)hash_file('sha256', $source))) {
            throw new RuntimeException('Independent backup digest mismatch');
        }
        $probe = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($probe->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Backup quick_check failed');
        }
        $rows = (int)$probe->query('SELECT COUNT(*) FROM engram_revisions')->fetchColumn();
        unset($probe);
        $final = $destination . DIRECTORY_SEPARATOR . 'engrams.sqlite';
        foreach ([$final, $final . '-wal', $final . '-shm'] as $occupied) {
            if (file_exists($occupied) || is_link($occupied)) {
                throw new RuntimeException('Restore destination is not empty');
            }
        }
        $temporary = $destination . DIRECTORY_SEPARATOR . '.engram-restore-' . bin2hex(random_bytes(16));
        $old = umask(0077);
        try {
            $input = @fopen($source, 'rb');
            $output = @fopen($temporary, 'x+b');
            if ($input === false || $output === false) {
                if (is_resource($input)) { fclose($input); }
                if (is_resource($output)) { fclose($output); }
                throw new RuntimeException('Restore copy could not start');
            }
            try {
                $copied = stream_copy_to_stream($input, $output);
                if ($copied === false || !fflush($output)) {
                    throw new RuntimeException('Restore copy failed');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            if (!@chmod($temporary, 0600)
                || !hash_equals($expectedSha256, (string)hash_file('sha256', $temporary))) {
                throw new RuntimeException('Restore copy failed integrity verification');
            }
            // link is an atomic no-clobber creation; rename could overwrite.
            if (!@link($temporary, $final)) {
                throw new RuntimeException('Concurrent restore or destination conflict');
            }
            unlink($temporary);
            $restored = new self($destination, $publicDocumentRoot);
            if ($restored->health()['quick_check'] !== 'ok') {
                throw new RuntimeException('Restored DB health check failed');
            }
            unset($restored);
            return ['sha256' => $expectedSha256, 'revision_count' => $rows, 'restored' => true];
        } finally {
            if (is_file($temporary) && !is_link($temporary)) { @unlink($temporary); }
            umask($old);
        }
    }

    /** Deny webroot, unsafe mode and symlink ancestors; directory must preexist. */
    private static function checkedPrivateDirectory(string $privateDirectory, string $publicDocumentRoot): string
    {
        if (is_link($privateDirectory) || !is_dir($privateDirectory)) {
            throw new RuntimeException('Private directory must exist without symlinks');
        }
        $directory = realpath($privateDirectory);
        $web = realpath($publicDocumentRoot);
        if ($directory === false || $web === false || !is_dir($web)
            || $directory === $web || str_starts_with($directory, $web . DIRECTORY_SEPARATOR)
            || (fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('Private directory isolation or permissions invalid');
        }
        $cursor = $privateDirectory;
        while (true) {
            if (is_link($cursor)) {
                throw new RuntimeException('Symlink component in private directory path');
            }
            $parent = dirname($cursor);
            if ($parent === $cursor || $cursor === '.' || $cursor === '') { break; }
            $cursor = $parent;
        }
        return $directory;
    }

    public function health(): array
    {
        $this->assertPrivateStorageFiles();
        return [
            'quick_check' => $this->db->query('PRAGMA quick_check')->fetchColumn(),
            'journal_mode' => $this->db->query('PRAGMA journal_mode')->fetchColumn(),
        ];
    }

    private function insert(array $row): void
    {
        $q = $this->db->prepare('INSERT INTO engram_revisions
            (subject,namespace,id,revision,kind,body,source_kind,source_ref,entry_state,previous_hash,revision_hash)
            VALUES (:subject,:namespace,:id,:revision,:kind,:body,:source_kind,:source_ref,:entry_state,:previous_hash,:revision_hash)');
        $q->execute($row);
    }
}
