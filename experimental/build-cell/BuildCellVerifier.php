<?php
declare(strict_types=1);

/**
 * KiCom Build Cell v1 - native verifier.
 *
 * This code is intentionally side-effect-light: it inspects a checked-out
 * project tree inside an isolated CI runner and never talks to production.
 */
final class KiComBuildCellVerifier
{
    private const MAX_FILES = 5000;
    private const MAX_TOTAL_BYTES = 67108864; // 64 MiB
    private const MAX_SINGLE_FILE = 4194304;  // 4 MiB

    /** @var list<string> */
    private const EXCLUDE_DIRS = ['.git', 'vendor', 'node_modules', 'dist', '.build-cell'];

    /** @return array<string,mixed> */
    public static function verify(string $root): array
    {
        $root = realpath($root) ?: '';
        if ($root === '' || !is_dir($root)) {
            return ['ok'=>false, 'code'=>'CELL_ROOT_INVALID'];
        }

        $files = [];
        $errors = [];
        $warnings = [];
        $total = 0;

        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $current) use ($root): bool {
                    $rel = str_replace('\\', '/', substr($current->getPathname(), strlen($root) + 1));
                    if ($current->isDir()) {
                        $base = basename($current->getPathname());
                        return !in_array($base, self::EXCLUDE_DIRS, true);
                    }
                    return $rel !== '';
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) continue;
            $path = $file->getPathname();
            $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if ($rel === '' || str_contains($rel, "\0")) continue;

            $size = (int)$file->getSize();
            if ($size > self::MAX_SINGLE_FILE) {
                $errors[] = ['path'=>$rel, 'code'=>'CELL_FILE_TOO_LARGE', 'bytes'=>$size];
                continue;
            }
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                $errors[] = ['path'=>$rel, 'code'=>'CELL_TREE_TOO_LARGE', 'bytes'=>$total];
                break;
            }
            if (count($files) >= self::MAX_FILES) {
                $errors[] = ['path'=>$rel, 'code'=>'CELL_TOO_MANY_FILES'];
                break;
            }

            $raw = @file_get_contents($path);
            if ($raw === false) {
                $errors[] = ['path'=>$rel, 'code'=>'CELL_READ_FAILED'];
                continue;
            }

            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if ($ext === 'php') {
                try {
                    token_get_all($raw, TOKEN_PARSE);
                } catch (ParseError $e) {
                    $errors[] = ['path'=>$rel, 'code'=>'PHP_SYNTAX_INVALID', 'message'=>$e->getMessage()];
                }
            } elseif ($ext === 'json') {
                json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = ['path'=>$rel, 'code'=>'JSON_INVALID', 'message'=>json_last_error_msg()];
                }
            }

            $lower = strtolower($rel);
            if ($lower === '.env' || str_ends_with($lower, '/.env') || preg_match('/(^|\/)(id_rsa|id_ed25519|.*\.pem|.*\.key)$/i', $rel)) {
                $errors[] = ['path'=>$rel, 'code'=>'SECRET_MATERIAL_FORBIDDEN'];
            }

            $files[] = [
                'path'=>$rel,
                'bytes'=>$size,
                'sha256'=>hash('sha256', $raw),
            ];
        }

        usort($files, static fn(array $a, array $b): int => strcmp((string)$a['path'], (string)$b['path']));
        $treeHashInput = '';
        foreach ($files as $f) $treeHashInput .= $f['sha256'].'  '.$f['path']."\n";

        return [
            'ok'=>count($errors) === 0,
            'code'=>count($errors) === 0 ? 'BUILD_CELL_VERIFY_PASS' : 'BUILD_CELL_VERIFY_FAIL',
            'root'=>$root,
            'files'=>count($files),
            'bytes'=>$total,
            'tree_sha256'=>hash('sha256', $treeHashInput),
            'errors'=>$errors,
            'warnings'=>$warnings,
            'inventory'=>$files,
        ];
    }

    /** @return array<string,mixed> */
    public static function composerPolicy(string $root): array
    {
        $path = rtrim($root, '/').'/composer.json';
        if (!is_file($path)) return ['ok'=>true, 'present'=>false, 'code'=>'COMPOSER_NOT_PRESENT'];
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j)) return ['ok'=>false, 'present'=>true, 'code'=>'COMPOSER_JSON_INVALID'];
        $scripts = isset($j['scripts']) && is_array($j['scripts']) && count($j['scripts']) > 0;
        $plugins = isset($j['config']['allow-plugins']) ? $j['config']['allow-plugins'] : null;
        return [
            'ok'=>true,
            'present'=>true,
            'code'=>'COMPOSER_POLICY_READY',
            'declares_scripts'=>$scripts,
            'allow_plugins'=>$plugins,
            'install_policy'=>'--no-scripts --no-plugins',
        ];
    }
}
