<?php
declare(strict_types=1);

/**
 * KiCom runtime bindings for the DEV router.
 *
 * lib.php + living.php must already be loaded by the first-party endpoint.
 * These bindings reuse existing KiCom workspace/source/build primitives but
 * deliberately stop before the self-update pending/install control plane.
 */
final class KiComDevRuntimeBindings
{
    /** @return array<string,Closure> */
    public static function handlers(): array
    {
        self::assertRuntime();
        return [
            'DEV_SOURCE_SNAPSHOT' => Closure::fromCallable([self::class, 'sourceSnapshot']),
            'DEV_WORKSPACE_READ' => Closure::fromCallable([self::class, 'workspaceRead']),
            'DEV_WORKSPACE_WRITE' => Closure::fromCallable([self::class, 'workspaceWrite']),
            'DEV_WORKSPACE_DELETE' => Closure::fromCallable([self::class, 'workspaceDelete']),
            'DEV_WORKSPACE_HISTORY' => Closure::fromCallable([self::class, 'workspaceHistory']),
            'DEV_BUILD_BEGIN' => Closure::fromCallable([self::class, 'buildBegin']),
            'DEV_BUILD_PATCH' => Closure::fromCallable([self::class, 'buildPatch']),
            'DEV_BUILD_STATUS' => Closure::fromCallable([self::class, 'buildStatus']),
            'DEV_BUILD_TEST' => Closure::fromCallable([self::class, 'buildTest']),
            'DEV_BUILD_FINALIZE_CANDIDATE' => Closure::fromCallable([self::class, 'buildFinalizeCandidate']),
            'DEV_CANDIDATE_READ' => Closure::fromCallable([self::class, 'candidateRead']),
            'DEV_CANDIDATE_DISCARD' => Closure::fromCallable([self::class, 'candidateDiscard']),
        ];
    }

    public static function ready(): array
    {
        try {
            self::assertRuntime();
            return ['ok'=>true, 'code'=>'DEV_BINDINGS_READY'];
        } catch (Throwable $e) {
            return ['ok'=>false, 'code'=>'DEV_BINDINGS_RUNTIME_MISSING'];
        }
    }

    private static function assertRuntime(): void
    {
        foreach ([
            'kicomSafeRelativePath','kicomEncodeBase64Url','kicomDecodeBase64Url',
            'kicomReadStage','kicomCurrentHash','kicomHistoryCount','kicomHistoryFiles',
            'kicomValidateContent','kicomAtomicStageWrite','kicomDeleteStageWithHistory',
            'kicomAutonomySourceList','kicomAutonomySourceRead',
            'kicomFastBuildBegin','kicomFastBuildMeta','kicomFastBuildPatch','kicomFastBuildStatus',
            'kicomFastBuildPrepareRelease','kicomFastBuildRm','kicomGenomeCurrent',
            'kicomSafeUpdatePath','kicomSelfUpdateZipInspect','kicomSelfUpdateRiskClass',
            'kicomAuthJsonWrite'
        ] as $fn) {
            if (!function_exists($fn)) throw new RuntimeException('missing '.$fn);
        }
        if (!class_exists('ZipArchive')) throw new RuntimeException('missing ZipArchive');
    }

    public static function sourceSnapshot(array $payload, array $auth): array
    {
        $path = trim((string)($payload['path'] ?? ''));
        if ($path === '') {
            $rows = kicomAutonomySourceList();
            return ['ok'=>true, 'code'=>'DEV_SOURCE_LIST', 'files'=>$rows, 'count'=>count($rows)];
        }
        $offset = max(0, (int)($payload['offset'] ?? 0));
        $length = max(1, min(49152, (int)($payload['length'] ?? 49152)));
        $r = kicomAutonomySourceRead($path, $offset, $length);
        return is_array($r) ? $r : ['ok'=>false, 'code'=>'DEV_SOURCE_READ_FAILED'];
    }

    public static function workspaceRead(array $payload, array $auth): array
    {
        $path = kicomSafeRelativePath((string)($payload['path'] ?? ''));
        if ($path === null) return ['ok'=>false, 'code'=>'INVALID_PATH'];
        $raw = kicomReadStage($path);
        if ($raw === null) return ['ok'=>false, 'code'=>'FILE_NOT_FOUND'];
        return [
            'ok'=>true,
            'code'=>'DEV_WORKSPACE_READ_OK',
            'path'=>$path,
            'bytes'=>strlen($raw),
            'sha256'=>hash('sha256', $raw),
            'revisions'=>kicomHistoryCount($path),
            'content_b64'=>kicomEncodeBase64Url($raw),
        ];
    }

    public static function workspaceWrite(array $payload, array $auth): array
    {
        $path = kicomSafeRelativePath((string)($payload['path'] ?? ''));
        if ($path === null) return ['ok'=>false, 'code'=>'INVALID_PATH'];
        $encoded = (string)($payload['content_b64'] ?? '');
        $raw = kicomDecodeBase64Url($encoded, true);
        if ($raw === null || strlen($raw) > 1048576) return ['ok'=>false, 'code'=>'CONTENT_INVALID'];
        $baseRaw = trim((string)($payload['base_sha256'] ?? ''));
        $base = strtoupper($baseRaw) === 'NEW' ? 'NEW' : strtolower($baseRaw);
        $current = kicomCurrentHash($path);
        if ($base === '' || !hash_equals($current, $base)) {
            return ['ok'=>false, 'code'=>'BASE_CONFLICT', 'current_sha256'=>$current];
        }
        $v = kicomValidateContent($path, $raw);
        if (($v['status'] ?? '') === 'error') return ['ok'=>false, 'code'=>'VALIDATION_FAILED', 'validation'=>$v['message'] ?? ''];
        $r = kicomAtomicStageWrite($path, $raw, 'dev_write', $base);
        if (is_array($r)) $r['validation'] = (string)($v['message'] ?? '');
        return is_array($r) ? $r : ['ok'=>false, 'code'=>'DEV_WORKSPACE_WRITE_FAILED'];
    }

    public static function workspaceDelete(array $payload, array $auth): array
    {
        $path = kicomSafeRelativePath((string)($payload['path'] ?? ''));
        if ($path === null) return ['ok'=>false, 'code'=>'INVALID_PATH'];
        $current = kicomCurrentHash($path);
        if ($current === 'NEW') return ['ok'=>false, 'code'=>'FILE_NOT_FOUND'];
        $base = strtolower(trim((string)($payload['base_sha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $base) || !hash_equals($current, $base)) {
            return ['ok'=>false, 'code'=>'BASE_CONFLICT', 'current_sha256'=>$current];
        }
        return kicomDeleteStageWithHistory($path);
    }

    public static function workspaceHistory(array $payload, array $auth): array
    {
        $path = kicomSafeRelativePath((string)($payload['path'] ?? ''));
        if ($path === null) return ['ok'=>false, 'code'=>'INVALID_PATH'];
        $out = [];
        foreach (array_slice(kicomHistoryFiles($path), 0, 50) as $row) {
            if (!is_array($row)) continue;
            $out[] = [
                'revision'=>(string)($row['revision'] ?? ''),
                'created_at'=>(string)($row['created_at'] ?? ''),
                'action'=>(string)($row['action'] ?? ''),
                'bytes'=>(int)($row['bytes'] ?? 0),
                'sha256'=>(string)($row['sha256'] ?? ''),
            ];
        }
        return ['ok'=>true, 'code'=>'DEV_WORKSPACE_HISTORY_OK', 'path'=>$path, 'revisions'=>$out];
    }

    public static function buildBegin(array $payload, array $auth): array
    {
        return kicomFastBuildBegin((string)$auth['session_id']);
    }

    public static function buildPatch(array $payload, array $auth): array
    {
        $find = kicomDecodeBase64Url((string)($payload['find_b64'] ?? ''), false);
        $replace = kicomDecodeBase64Url((string)($payload['replace_b64'] ?? ''), true);
        if ($find === null || $replace === null) return ['ok'=>false, 'code'=>'PATCH_ENCODING_INVALID'];
        return kicomFastBuildPatch(
            (string)$auth['session_id'],
            (string)($payload['build_id'] ?? ''),
            (string)($payload['path'] ?? ''),
            (string)($payload['base_sha256'] ?? ''),
            $find,
            $replace
        );
    }

    public static function buildStatus(array $payload, array $auth): array
    {
        return kicomFastBuildStatus((string)$auth['session_id'], (string)($payload['build_id'] ?? ''));
    }

    public static function buildTest(array $payload, array $auth): array
    {
        $x = kicomFastBuildMeta((string)$auth['session_id'], (string)($payload['build_id'] ?? ''));
        if (empty($x['ok'])) return $x;
        $m = $x['meta'];
        $src = $x['dir'].'/src';
        $errors = [];
        $warn = [];
        $checked = 0;
        foreach (array_keys($m['base_files'] ?? []) as $path) {
            $path = kicomSafeUpdatePath((string)$path);
            if ($path === null || !is_file($src.'/'.$path)) {
                $errors[] = ['path'=>(string)$path, 'code'=>'BUILD_FILE_MISSING'];
                continue;
            }
            $raw = @file_get_contents($src.'/'.$path);
            if ($raw === false) {
                $errors[] = ['path'=>$path, 'code'=>'BUILD_READ_FAILED'];
                continue;
            }
            $v = kicomValidateContent($path, $raw);
            $checked++;
            if (($v['status'] ?? '') === 'error') $errors[] = ['path'=>$path, 'code'=>(string)($v['message'] ?? 'VALIDATION_FAILED')];
            elseif (($v['status'] ?? '') === 'warn') $warn[] = ['path'=>$path, 'code'=>(string)($v['message'] ?? 'WARN')];
        }
        return [
            'ok'=>count($errors) === 0,
            'code'=>count($errors) === 0 ? 'DEV_BUILD_TEST_PASS' : 'DEV_BUILD_TEST_FAIL',
            'checked'=>$checked,
            'errors'=>$errors,
            'warnings'=>$warn,
        ];
    }

    public static function buildFinalizeCandidate(array $payload, array $auth): array
    {
        $sid = (string)$auth['session_id'];
        $id = (string)($payload['build_id'] ?? '');
        $version = trim((string)($payload['version'] ?? ''));
        $reason = trim((string)($payload['reason'] ?? 'DEV candidate'));
        $summary = trim((string)($payload['summary'] ?? ''));

        if ($version !== '') {
            $prep = kicomFastBuildPrepareRelease($sid, $id, $version, $reason, $summary);
            if (empty($prep['ok'])) return $prep;
        }
        return self::exportCandidate($sid, $id);
    }

    private static function exportCandidate(string $sid, string $id): array
    {
        $x = kicomFastBuildMeta($sid, $id);
        if (empty($x['ok'])) return $x;
        $m = $x['meta'];
        if (($m['status'] ?? '') !== 'open') return ['ok'=>false, 'code'=>'BUILD_NOT_OPEN'];
        $src = $x['dir'].'/src';
        $lib = @file_get_contents($src.'/lib.php');
        if (!is_string($lib) || !preg_match("/const\\s+KICOM_VERSION\\s*=\\s*'([0-9]+\\.[0-9]+\\.[0-9]+)'/", $lib, $vm)) {
            return ['ok'=>false, 'code'=>'BUILD_VERSION_MISSING'];
        }
        $version = $vm[1];
        if (defined('KICOM_VERSION') && version_compare($version, (string)KICOM_VERSION, '<=')) {
            return ['ok'=>false, 'code'=>'BUILD_VERSION_NOT_NEWER', 'version'=>$version];
        }

        $gp = $src.'/genome/genome.json';
        $g = json_decode((string)@file_get_contents($gp), true);
        $current = kicomGenomeCurrent();
        if (!is_array($g) || !is_array($current)) return ['ok'=>false, 'code'=>'BUILD_GENOME_INVALID'];
        if ((string)($g['version'] ?? '') !== $version
            || !hash_equals((string)($g['parent'] ?? ''), (string)($current['id'] ?? ''))
            || (int)($g['generation'] ?? 0) !== ((int)($current['generation'] ?? 0) + 1)) {
            return ['ok'=>false, 'code'=>'BUILD_LINEAGE_INVALID'];
        }

        foreach ($g['components'] ?? [] as &$component) {
            if (!is_array($component)) return ['ok'=>false, 'code'=>'BUILD_GENOME_COMPONENT_INVALID'];
            $p = kicomSafeUpdatePath((string)($component['path'] ?? ''));
            if ($p === null || !is_file($src.'/'.$p)) return ['ok'=>false, 'code'=>'BUILD_GENOME_COMPONENT_MISSING'];
            $component['sha256'] = hash_file('sha256', $src.'/'.$p) ?: '';
        }
        unset($component);
        $g['created_at'] = gmdate('c');
        $gj = json_encode($g, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($gj) || @file_put_contents($gp, $gj."\n", LOCK_EX) === false) return ['ok'=>false, 'code'=>'BUILD_GENOME_WRITE_FAILED'];

        $paths = array_keys($m['base_files'] ?? []);
        sort($paths, SORT_STRING);
        $manifest = '';
        foreach ($paths as $p) {
            $p = kicomSafeUpdatePath((string)$p);
            if ($p === null || !is_file($src.'/'.$p)) return ['ok'=>false, 'code'=>'BUILD_FILE_MISSING'];
            $raw = @file_get_contents($src.'/'.$p);
            if ($raw === false) return ['ok'=>false, 'code'=>'BUILD_READ_FAILED'];
            $v = kicomValidateContent($p, $raw);
            if (($v['status'] ?? '') === 'error') return ['ok'=>false, 'code'=>'BUILD_VALIDATION_FAILED', 'path'=>$p];
            $manifest .= (hash_file('sha256', $src.'/'.$p) ?: '').'  '.$p."\n";
        }
        if (@file_put_contents($src.'/MANIFEST.sha256', $manifest, LOCK_EX) === false) return ['ok'=>false, 'code'=>'BUILD_MANIFEST_FAILED'];

        $zip = $x['dir'].'/KiCom-'.$version.'-dev-candidate.zip';
        $z = new ZipArchive();
        if ($z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return ['ok'=>false, 'code'=>'BUILD_ZIP_OPEN_FAILED'];
        foreach (array_merge($paths, ['MANIFEST.sha256']) as $p) {
            if (!$z->addFile($src.'/'.$p, $p)) { $z->close(); return ['ok'=>false, 'code'=>'BUILD_ZIP_ADD_FAILED', 'path'=>$p]; }
        }
        $z->close();

        $inspect = kicomSelfUpdateZipInspect($zip);
        if (empty($inspect['ok'])) return ['ok'=>false, 'code'=>'BUILD_VERIFIER_REJECTED', 'detail'=>(string)($inspect['code'] ?? 'UNKNOWN')];
        $risk = kicomSelfUpdateRiskClass($inspect);
        $sha = hash_file('sha256', $zip) ?: '';
        $m['status'] = 'candidate_ready';
        $m['candidate_version'] = $version;
        $m['package_file'] = basename($zip);
        $m['package_sha256'] = $sha;
        $m['risk_class'] = (string)($risk['class'] ?? '');
        $m['candidate_ready_at'] = gmdate('c');
        if (!kicomAuthJsonWrite($x['dir'].'/meta.json', $m)) return ['ok'=>false, 'code'=>'BUILD_META_FAILED'];

        return [
            'ok'=>true,
            'code'=>'DEV_CANDIDATE_READY',
            'build_id'=>$id,
            'candidate_version'=>$version,
            'package_sha256'=>$sha,
            'risk_class'=>(string)($risk['class'] ?? ''),
            'bytes'=>(int)@filesize($zip),
            'production_pending_created'=>false,
        ];
    }

    public static function candidateRead(array $payload, array $auth): array
    {
        $x = kicomFastBuildMeta((string)$auth['session_id'], (string)($payload['build_id'] ?? ''));
        if (empty($x['ok'])) return $x;
        $m = $x['meta'];
        if (($m['status'] ?? '') !== 'candidate_ready') return ['ok'=>false, 'code'=>'CANDIDATE_NOT_READY'];
        $name = basename((string)($m['package_file'] ?? ''));
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name)) return ['ok'=>false, 'code'=>'CANDIDATE_FILE_INVALID'];
        $file = $x['dir'].'/'.$name;
        if (!is_file($file)) return ['ok'=>false, 'code'=>'CANDIDATE_FILE_MISSING'];
        $size = (int)filesize($file);
        $offset = max(0, (int)($payload['offset'] ?? 0));
        $length = max(1, min(49152, (int)($payload['length'] ?? 49152)));
        if ($offset > $size) return ['ok'=>false, 'code'=>'CANDIDATE_OFFSET_INVALID'];
        $h = @fopen($file, 'rb');
        if ($h === false) return ['ok'=>false, 'code'=>'CANDIDATE_READ_FAILED'];
        fseek($h, $offset);
        $raw = (string)fread($h, $length);
        fclose($h);
        $next = $offset + strlen($raw);
        return [
            'ok'=>true,
            'code'=>'DEV_CANDIDATE_CHUNK',
            'build_id'=>(string)$m['id'],
            'candidate_version'=>(string)($m['candidate_version'] ?? ''),
            'package_sha256'=>(string)($m['package_sha256'] ?? ''),
            'bytes'=>$size,
            'offset'=>$offset,
            'next_offset'=>$next,
            'eof'=>$next >= $size,
            'data_b64'=>kicomEncodeBase64Url($raw),
        ];
    }

    public static function candidateDiscard(array $payload, array $auth): array
    {
        $x = kicomFastBuildMeta((string)$auth['session_id'], (string)($payload['build_id'] ?? ''));
        if (empty($x['ok'])) return $x;
        $id = (string)($x['meta']['id'] ?? '');
        kicomFastBuildRm((string)$x['dir']);
        return ['ok'=>true, 'code'=>'DEV_CANDIDATE_DISCARDED', 'build_id'=>$id];
    }
}
