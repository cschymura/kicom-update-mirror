<?php
declare(strict_types=1);

/**
 * Read-only private passkey -> Engram owner/rights registry.
 *
 * Provisioning/revocation belongs to the existing independently authorized
 * KiCom control plane. This class NEVER creates a default owner, auto-enrolls
 * a passkey, assigns permissions from a client, or modifies registry content.
 * The trusted host code supplies the absolute config path. Registry contents
 * and real paths must never enter public GitHub/Slack/HTTP logs.
 */
final class KiComEngramPrivateOwnerRegistry
{
    private string $path;
    private string $dir;

    public function __construct(string $trustedRegistryPath, string $publicDocumentRoot)
    {
        $web = realpath($publicDocumentRoot);
        $dir = realpath(dirname($trustedRegistryPath));
        if ($web === false || $dir === false || !is_dir($web)
            || is_link($trustedRegistryPath) || !is_file($trustedRegistryPath)
            || is_link(dirname($trustedRegistryPath))
            || $dir === $web || str_starts_with($dir, $web.DIRECTORY_SEPARATOR)
            || (fileperms($dir) & 0077) !== 0) {
            throw new RuntimeException('ENGRAM_OWNER_REGISTRY_UNAVAILABLE');
        }
        // Registry is an operator-owned fixed filename in a preexisting,
        // private directory, not a path supplied by an HTTP request.
        if (basename($trustedRegistryPath) !== 'engram-owners.json') {
            throw new RuntimeException('ENGRAM_OWNER_REGISTRY_UNAVAILABLE');
        }
        $this->path = $dir.'/engram-owners.json';
        $this->dir = $dir;
        if (realpath($trustedRegistryPath) !== $this->path) {
            throw new RuntimeException('ENGRAM_OWNER_REGISTRY_UNAVAILABLE');
        }
        $this->checkFile();
    }

    private function checkFile(): void
    {
        clearstatcache(true, $this->path);
        clearstatcache(true, $this->dir);
        if (!is_dir($this->dir) || is_link($this->dir)
            || (fileperms($this->dir) & 0077) !== 0
            || is_link($this->path) || !is_file($this->path)) {
            throw new RuntimeException('ENGRAM_OWNER_REGISTRY_UNAVAILABLE');
        }
        $stat = @stat($this->path);
        if ($stat === false || $stat['nlink'] !== 1
            || ($stat['mode'] & 0077) !== 0
            || $stat['size'] <= 0 || $stat['size'] > 65536) {
            throw new RuntimeException('ENGRAM_OWNER_REGISTRY_UNAVAILABLE');
        }
    }

    /** Returns an owner record only for an explicitly provisioned fingerprint. */
    public function __invoke(string $fingerprint): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) return null;
        $this->checkFile();
        $h = @fopen($this->path, 'rb');
        if ($h === false) throw new RuntimeException('ENGRAM_OWNER_REGISTRY_UNAVAILABLE');
        try {
            $st = fstat($h);
            if ($st === false || $st['nlink'] !== 1
                || ($st['mode'] & 0077) !== 0 || $st['size'] <= 0
                || $st['size'] > 65536) {
                throw new RuntimeException('ENGRAM_OWNER_REGISTRY_UNAVAILABLE');
            }
            $raw = stream_get_contents($h, 65537);
            if (!is_string($raw) || strlen($raw) !== $st['size']) {
                throw new RuntimeException('ENGRAM_OWNER_REGISTRY_UNAVAILABLE');
            }
        } finally {
            fclose($h);
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc) || ($doc['schema'] ?? null) !== 1
            || !is_array($doc['owners'] ?? null)
            || count($doc['owners']) > 128) {
            throw new RuntimeException('ENGRAM_OWNER_REGISTRY_UNAVAILABLE');
        }
        $owner = $doc['owners'][$fingerprint] ?? null;
        return is_array($owner) ? $owner : null;
    }
}
