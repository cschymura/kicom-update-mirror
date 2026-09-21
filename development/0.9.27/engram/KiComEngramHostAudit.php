<?php
declare(strict_types=1);

/**
 * First-party admin-only READ-ONLY Engram host diagnostic. No claims of
 * complete cross-vhost isolation or backup restorability are made by this
 * file-system inspection. No absolute paths, UIDs, configuration contents,
 * registry records, tokens or filenames of adjacent apps are returned.
 * Never pass a webRoot originating in a browser request.
 */
final class KiComEngramHostAudit
{
    /** @return array<string,string> fixed, public-to-admin status codes */
    public static function inspect(string $trustedWebRoot): array
    {
        $report = [
            'code' => 'HOST_EVIDENCE_INCOMPLETE_API_INACTIVE',
            'webroot' => 'unknown',
            'private_root' => 'unknown',
            'private_subdirs' => 'unknown',
            'host_config' => 'unknown',
            'owner_registry' => 'unknown',
            'sqlite_file' => 'unknown',
            'php_open_basedir' => 'unknown',
            'sibling_application_access' => 'unknown',
            'vhost_alias_inventory' => 'not_verified',
            'php_uid_separation' => 'not_verified',
            'cross_app_isolation' => 'not_verified',
            'backup_restore' => 'not_verified',
            'personal_memory' => 'not_assessed',
        ];
        $web = realpath($trustedWebRoot);
        if (!is_string($web) || $web === '/' || !is_dir($web) || is_link($trustedWebRoot)) {
            return $report;
        }
        $report['webroot'] = 'recognized';
        $base = dirname($web);
        $private = $base.'/engram-private';
        if (!self::privateDir($private) || realpath($private) !== $private) {
            $report['private_root'] = 'missing_or_unprotected';
            return $report;
        }
        $report['private_root'] = 'restricted';
        $dirs = ['data','backups','owners','consent','review','stepup'];
        $report['private_subdirs'] = 'restricted';
        foreach ($dirs as $name) {
            if (!self::privateDir($private.'/'.$name)) {
                $report['private_subdirs'] = 'missing_or_unprotected';
                break;
            }
        }
        $cfg = $private.'/engram-host.json';
        $report['host_config'] = self::privateFile($cfg) ? 'restricted' : 'missing_or_unprotected';
        // Deliberately never read or return the file content or its exact path.
        $owner = $private.'/owners/engram-owners.json';
        $report['owner_registry'] = self::privateFile($owner) ? 'restricted' : 'missing_or_unprotected';
        $db = $private.'/data/engrams.sqlite';
        $report['sqlite_file'] = self::privateFile($db, 1, 1073741824)
            ? 'restricted' : (file_exists($db) || is_link($db) ? 'unprotected_or_unusable' : 'missing');

        $openBasedir = trim((string)ini_get('open_basedir'));
        $report['php_open_basedir'] = $openBasedir === '' ? 'not_set' : 'set_scope_not_verified';
        // is_readable on sibling directories does NOT prove they are other
        // vhosts or run under the same UID. It merely signals shared filesystem
        // visibility; no traversal or disclosure of neighboring app contents.
        if (is_readable($base) && is_dir($base)) {
            $seen = false;
            $readable = false;
            $entries = @scandir($base);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry==='.' || $entry==='..' || $entry===basename($web)
                        || $entry==='engram-private') continue;
                    $other = $base.'/'.$entry;
                    if (is_link($other) || !is_dir($other)) continue;
                    $seen = true;
                    if (is_readable($other)) $readable = true;
                }
                $report['sibling_application_access'] = $readable ? 'readable_sibling_directory_detected'
                    : ($seen ? 'no_readable_sibling_directory_detected' : 'no_sibling_directory_detected');
            }
        }
        // The status intentionally CANNOT become COMPLETE in this automated
        // probe: host mapping, UID boundaries and backup restoration require
        // separate positive evidence from a trusted host-capable review.
        return $report;
    }

    private static function privateDir(string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return is_array($stat) && ($stat['mode'] & 0170000) === 0040000
            && ($stat['mode'] & 0077) === 0 && !is_link($path)
            && is_dir($path) && is_readable($path);
    }

    private static function privateFile(string $path, int $min = 1, int $max = 65536): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
            && ($stat['mode'] & 0077) === 0 && ($stat['nlink'] ?? 0) === 1
            && ($stat['size'] ?? 0) >= $min && ($stat['size'] ?? PHP_INT_MAX) <= $max
            && !is_link($path) && is_file($path) && is_readable($path);
    }
}
