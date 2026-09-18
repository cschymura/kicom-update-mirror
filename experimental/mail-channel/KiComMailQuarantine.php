<?php
declare(strict_types=1);

final class KiComMailQuarantine
{
    private const MAX_ATTACHMENT_BYTES = 26214400;
    private string $root;

    public function __construct(string $root)
    {
        if ($root === '') {
            throw new InvalidArgumentException('MAIL_QUARANTINE_ROOT_REQUIRED');
        }
        $this->root = rtrim($root, '/\\');
        $this->ensureDirectory($this->root);
        $deny = $this->root . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\nDeny from all\n", LOCK_EX);
            @chmod($deny, 0600);
        }
    }

    public function store(array $attachment, string $messageId): array
    {
        $data = $attachment['data'] ?? null;
        if (!is_string($data)) {
            throw new InvalidArgumentException('MAIL_ATTACHMENT_DATA_REQUIRED');
        }
        $size = strlen($data);
        if ($size < 0 || $size > self::MAX_ATTACHMENT_BYTES) {
            throw new RuntimeException('MAIL_ATTACHMENT_SIZE_LIMIT');
        }

        $sha = hash('sha256', $data);
        $safeName = $this->safeName((string)($attachment['filename'] ?? 'attachment.bin'));
        $declaredType = strtolower(trim((string)($attachment['content_type'] ?? 'application/octet-stream')));
        $zipLike = $this->isZip($safeName, $declaredType, $data);
        $day = gmdate('Ymd');
        $dir = $this->root . '/' . $day;
        $this->ensureDirectory($dir);

        $blob = $dir . '/' . $sha . '.bin';
        if (!is_file($blob)) {
            $tmp = $blob . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $data, LOCK_EX) !== $size) {
                @unlink($tmp);
                throw new RuntimeException('MAIL_QUARANTINE_WRITE_FAILED');
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $blob)) {
                @unlink($tmp);
                throw new RuntimeException('MAIL_QUARANTINE_COMMIT_FAILED');
            }
            @chmod($blob, 0600);
        }

        $meta = [
            'schema' => 1,
            'quarantine_id' => $day . '-' . substr($sha, 0, 24),
            'sha256' => $sha,
            'size' => $size,
            'filename' => $safeName,
            'declared_content_type' => $declaredType,
            'zip_like' => $zipLike,
            'trust' => 'UNTRUSTED',
            'executable' => false,
            'auto_install' => false,
            'auto_extract' => false,
            'verifier_required_for_release' => $zipLike,
            'message_id_sha256' => hash('sha256', $messageId),
            'stored_at' => gmdate('c'),
        ];
        $metaPath = $dir . '/' . $sha . '-' . bin2hex(random_bytes(4)) . '.json';
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($metaPath, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('MAIL_QUARANTINE_METADATA_FAILED');
        }
        @chmod($metaPath, 0600);
        return $meta;
    }

    private function safeName(string $name): string
    {
        $name = str_replace(["\0", "\r", "\n", '/', '\\'], '_', trim($name));
        $name = preg_replace('/[^\pL\pN._()\[\] -]+/u', '_', $name) ?? 'attachment.bin';
        $name = trim($name, " .");
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment.bin';
        }
        return mb_substr($name, 0, 180);
    }

    private function isZip(string $filename, string $contentType, string $data): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $magic = substr($data, 0, 4);
        return $ext === 'zip'
            || in_array($contentType, ['application/zip', 'application/x-zip-compressed'], true)
            || in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('MAIL_QUARANTINE_DIR_FAILED');
        }
        @chmod($dir, 0700);
    }
}
