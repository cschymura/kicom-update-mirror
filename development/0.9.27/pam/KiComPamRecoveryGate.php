<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPamSnapshotOrder.php';

/**
 * Development-only, read-only recovery preflight for the trusted KiCom runtime.
 *
 * No path or callback is supplied externally. The existing KiCom snapshot
 * directory and the existing full SQLite file-verification helper are the only
 * accepted sources. A same-second legacy collision must not be guessed away.
 * This class has no restore, replacement, deployment, or approval method.
 */
final class KiComPamRecoveryGate
{
    public static function inspect(): array
    {
        foreach (['kicomSqliteSnapshotDir', 'kicomSqliteSnapshotMetaFiles',
            'kicomSqliteVerifyFile'] as $fn) {
            if (!function_exists($fn)) {
                return ['ok' => false, 'code' => 'TRUSTED_RECOVERY_RUNTIME_UNAVAILABLE'];
            }
        }
        $base = realpath(kicomSqliteSnapshotDir());
        if ($base === false || !is_dir($base)) {
            return ['ok' => false, 'code' => 'SNAPSHOT_STORE_UNAVAILABLE'];
        }
        $files = kicomSqliteSnapshotMetaFiles();
        if (!is_array($files) || $files === [] || count($files) > 64) {
            return ['ok' => false, 'code' => 'SNAPSHOT_INVENTORY_INVALID'];
        }
        $rows = [];
        foreach ($files as $file) {
            if (!is_string($file) || is_link($file) || !is_file($file)
                || dirname(realpath($file) ?: '') !== $base
                || filesize($file) === false || filesize($file) > 8192) {
                return ['ok' => false, 'code' => 'SNAPSHOT_MANIFEST_PATH_INVALID'];
            }
            $raw = file_get_contents($file);
            if (!is_string($raw)) {
                return ['ok' => false, 'code' => 'SNAPSHOT_MANIFEST_UNREADABLE'];
            }
            try {
                $row = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                return ['ok' => false, 'code' => 'SNAPSHOT_MANIFEST_INVALID'];
            }
            if (!is_array($row) || !is_string($row['id'] ?? null)
                || !preg_match('/^[0-9]{14}-[a-f0-9]{10}$/D', $row['id'])
                || basename($file) !== 'snapshot-' . $row['id'] . '.json') {
                return ['ok' => false, 'code' => 'SNAPSHOT_MANIFEST_ID_INVALID'];
            }
            $rows[] = $row;
        }
        $selected = KiComPamSnapshotOrder::select($rows, static function (array $row) use ($base): bool {
            $id = $row['id'] ?? null;
            $name = $row['file_name'] ?? null;
            $sha = $row['sha256'] ?? null;
            if (!is_string($id) || !is_string($name)
                || $name !== 'snapshot-' . $id . '.sqlite'
                || !is_string($sha)) {
                return false;
            }
            $file = $base . '/' . $name;
            return is_file($file) && !is_link($file)
                && dirname(realpath($file) ?: '') === $base
                && hash_file('sha256', $file) === $sha
                && !empty(kicomSqliteVerifyFile($file, true)['ok']);
        });
        if (empty($selected['ok'])) {
            return ['ok' => false, 'code' => $selected['code'] ?? 'SNAPSHOT_ORDER_UNVERIFIED'];
        }
        $chosen = $selected['snapshot'];
        return ['ok' => true, 'code' => 'EXACT_VERIFIED_RECOVERY_CANDIDATE',
            'snapshot_id' => $chosen['id'],
            'snapshot_sha256' => $chosen['sha256'],
            'inspection_only' => true, 'restore_permitted' => false];
    }
}
