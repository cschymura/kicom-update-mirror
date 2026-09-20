<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramStore.php';

/**
 * ISOLATED DEV ONLY: generate a synthetic SQLite snapshot and encrypt it to a
 * public Curve25519 key whose private counterpart NEVER leaves the operator PC.
 * No real DB/memory input accepted. Not a web route or an update installer.
 * Encryption alone DOES NOT authenticate its sender or authorize an export.
 */
final class KiComEngramSyntheticTransfer
{
    public static function create(string $recipientPublicHex, string $privateOutputDir, string $webDocumentRoot): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Synthetic transfer fixture is CLI-only');
        }
        if (!function_exists('sodium_crypto_box_seal')
            || !preg_match('/\A[a-f0-9]{64}\z/D', $recipientPublicHex)) {
            throw new RuntimeException('Recipient public key or libsodium unavailable');
        }
        $public = hex2bin($recipientPublicHex);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new RuntimeException('Recipient public key invalid');
        }
        if (is_link($privateOutputDir) || !is_dir($privateOutputDir)
            || (fileperms($privateOutputDir) & 0077) !== 0) {
            throw new RuntimeException('Private output directory must exist with private permissions');
        }
        $output = realpath($privateOutputDir);
        $web = realpath($webDocumentRoot);
        if ($output === false || $web === false || !is_dir($web)
            || $output === $web || str_starts_with($output, $web . DIRECTORY_SEPARATOR)
            || str_starts_with($web, $output . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Synthetic output and website directories not isolated');
        }
        $scratch = $output . '/.synthetic-only-' . bin2hex(random_bytes(12));
        if (!@mkdir($scratch, 0700)) {
            throw new RuntimeException('Synthetic scratch directory cannot be created');
        }
        $snapshots = $scratch . '/snapshots';
        try {
            if (!@mkdir($snapshots, 0700)) {
                throw new RuntimeException('Synthetic snapshot directory cannot be created');
            }
            $store = new KiComEngramStore($scratch, $web);
            $original = $store->create(
                'synthetic-subject', 'synthetic-project', 'technical',
                'ENGRAM_SYNTHETIC_ONLY::approved test record 1',
                'synthetic_test', 'fixture://offline-copy'
            );
            $store->revise(
                'synthetic-subject', 'synthetic-project', $original['id'],
                1, $original['revision_hash'],
                'ENGRAM_SYNTHETIC_ONLY::approved test record 2',
                'synthetic_test', 'fixture://offline-copy'
            );
            $backup = $store->backup($snapshots, $web);
            $snapshotPath = $snapshots . '/' . $backup['filename'];
            $size = filesize($snapshotPath);
            if ($size === false || $size < 1 || $size > 2097152
                || $backup['revision_count'] !== 2) {
                throw new RuntimeException('Synthetic backup size or fixture count invalid');
            }
            $plain = file_get_contents($snapshotPath);
            if (!is_string($plain) || strlen($plain) !== $size
                || !hash_equals($backup['sha256'], hash('sha256', $plain))) {
                throw new RuntimeException('Synthetic backup content integrity failed');
            }
            $cipher = sodium_crypto_box_seal($plain, $public);
            $envelope = [
                'format' => 'engram-synthetic-sealed-v1',
                'recipient_public_sha256' => hash('sha256', $public),
                'snapshot_sha256' => $backup['sha256'],
                'snapshot_bytes' => $size,
                'revision_count' => 2,
                'ciphertext_b64' => base64_encode($cipher),
            ];
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $name = 'engram-synthetic-sealed-' . bin2hex(random_bytes(12)) . '.json';
            $destination = $output . '/' . $name;
            $mask = umask(0077);
            try {
                $handle = @fopen($destination, 'x+b');
                if ($handle === false) {
                    throw new RuntimeException('Encrypted output file already exists');
                }
                try {
                    if (!@chmod($destination, 0600)
                        || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                        throw new RuntimeException('Encrypted output write failed');
                    }
                } finally {
                    fclose($handle);
                }
            } finally {
                umask($mask);
            }
            return [
                'filename' => $name,
                'bundle_sha256' => hash('sha256', $json),
                'synthetic_only' => true,
                'plaintext_exposed_in_bundle' => false,
                'revision_count' => 2,
                'recipient_private_key_on_host' => false,
            ];
        } finally {
            // Only delete this freshly allocated synthetic scratch location.
            unset($store);
            foreach ([$snapshots, $scratch] as $dir) {
                if (!is_dir($dir) || is_link($dir)) continue;
                $files = scandir($dir);
                if ($files === false) continue;
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $item = $dir . '/' . $file;
                    if (is_file($item) && !is_link($item)) @unlink($item);
                }
                @rmdir($dir);
            }
        }
    }
}
