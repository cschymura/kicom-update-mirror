<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionProtocol.php';

/**
 * Parent-side registry for one-time expansion enrollment.
 *
 * Persistence contains derived enrollment verifiers and public cell metadata,
 * never plaintext FTP credentials or plaintext enrollment tokens.
 */
final class KiComExpansionRegistry
{
    private string $dir;
    /** @var array<string,mixed> */
    private array $parent;

    /** @param array<string,mixed> $parentDescriptor */
    public function __construct(string $dir, array $parentDescriptor)
    {
        $this->dir = rtrim($dir, '/');
        foreach (['cell_id','public_key','base_url','generation'] as $key) {
            if (!isset($parentDescriptor[$key])) throw new InvalidArgumentException('PARENT_DESCRIPTOR_INVALID');
        }
        $this->parent = $parentDescriptor;
        $this->ensureDir($this->dir.'/expansions');
        $this->ensureDir($this->dir.'/cells');
    }

    /**
     * @return array<string,mixed>
     */
    public function prepare(string $targetBaseUrl, string $remoteWebRoot = '/', int $ttl = 3600): array
    {
        $targetBaseUrl = rtrim(trim($targetBaseUrl), '/');
        $parts = parse_url($targetBaseUrl);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return ['ok'=>false,'code'=>'EXPANSION_TARGET_HTTPS_REQUIRED'];
        }
        $remoteWebRoot = self::normalizeRemoteRoot($remoteWebRoot);
        if ($remoteWebRoot === null) return ['ok'=>false,'code'=>'EXPANSION_REMOTE_ROOT_INVALID'];

        $id = KiComExpansionProtocol::randomId('exp-');
        $token = KiComExpansionProtocol::enrollmentToken();
        $now = time();
        $ttl = max(300, min(86400, $ttl));
        $row = [
            'schema'=>1,
            'expansion_id'=>$id,
            'state'=>'prepared',
            'target_base_url'=>$targetBaseUrl,
            'child_base_url'=>$targetBaseUrl.'/kicom',
            'remote_web_root'=>$remoteWebRoot,
            'remote_cell_directory'=>rtrim($remoteWebRoot,'/').'/kicom',
            'enrollment_verifier'=>KiComExpansionProtocol::enrollmentVerifier($token),
            'created_at'=>gmdate('c',$now),
            'expires_at'=>gmdate('c',$now+$ttl),
            'expires_unix'=>$now+$ttl,
            'parent_id'=>(string)$this->parent['cell_id'],
        ];
        if (!$this->writeJson($this->expansionPath($id), $row)) return ['ok'=>false,'code'=>'EXPANSION_PREPARE_WRITE_FAILED'];

        return [
            'ok'=>true,
            'code'=>'EXPANSION_PREPARED',
            'expansion_id'=>$id,
            'target_base_url'=>$targetBaseUrl,
            'child_base_url'=>$row['child_base_url'],
            'remote_cell_directory'=>$row['remote_cell_directory'],
            'enrollment_token'=>$token,
            'expires_at'=>$row['expires_at'],
        ];
    }

    public function markDeployed(string $expansionId): array
    {
        return $this->transition($expansionId, ['prepared'], 'deployed');
    }

    public function markReachable(string $expansionId): array
    {
        return $this->transition($expansionId, ['prepared','deployed'], 'reachable');
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return array<string,mixed>
     */
    public function enroll(string $expansionId, array $descriptor, string $proof): array
    {
        $path = $this->expansionPath($expansionId);
        $row = $this->readJson($path);
        if ($row === null) return ['ok'=>false,'code'=>'EXPANSION_NOT_FOUND'];
        if (!in_array((string)($row['state'] ?? ''), ['prepared','deployed','reachable'], true)) {
            return ['ok'=>false,'code'=>'EXPANSION_NOT_ENROLLABLE'];
        }
        if ((int)($row['expires_unix'] ?? 0) < time()) {
            $row['state']='expired';
            $this->writeJson($path,$row);
            return ['ok'=>false,'code'=>'EXPANSION_ENROLLMENT_EXPIRED'];
        }

        foreach (['cell_id','public_key','base_url'] as $key) {
            if (!isset($descriptor[$key]) || !is_string($descriptor[$key]) || trim($descriptor[$key])==='') {
                return ['ok'=>false,'code'=>'CELL_DESCRIPTOR_INVALID'];
            }
        }
        $expectedBase = rtrim((string)$row['child_base_url'],'/');
        if (!hash_equals($expectedBase, rtrim((string)$descriptor['base_url'],'/'))) {
            return ['ok'=>false,'code'=>'CELL_BASE_URL_MISMATCH'];
        }
        $pk = KiComExpansionProtocol::b64urlDecode((string)$descriptor['public_key']);
        if ($pk === null || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ['ok'=>false,'code'=>'CELL_PUBLIC_KEY_INVALID'];
        }
        if (!KiComExpansionProtocol::verifyEnrollmentProof($descriptor, strtolower($proof), (string)$row['enrollment_verifier'])) {
            return ['ok'=>false,'code'=>'EXPANSION_ENROLLMENT_PROOF_INVALID'];
        }

        $parentGeneration = max(0,(int)$this->parent['generation']);
        $rootId = (string)($this->parent['root_id'] ?? $this->parent['cell_id']);
        $cell = [
            'schema'=>1,
            'cell_id'=>(string)$descriptor['cell_id'],
            'root_id'=>$rootId,
            'parent_id'=>(string)$this->parent['cell_id'],
            'generation'=>$parentGeneration+1,
            'public_key'=>(string)$descriptor['public_key'],
            'base_url'=>$expectedBase,
            'state'=>'active',
            'capabilities'=>self::normalizeCapabilities($descriptor['capabilities'] ?? []),
            'created_at'=>gmdate('c'),
            'last_seen_at'=>gmdate('c'),
            'expansion_id'=>$expansionId,
        ];
        if (!$this->writeJson($this->cellPath($cell['cell_id']), $cell)) {
            return ['ok'=>false,'code'=>'CELL_REGISTRY_WRITE_FAILED'];
        }

        // Make enrollment one-time: erase verifier after successful acceptance.
        $row['state']='active';
        $row['cell_id']=$cell['cell_id'];
        $row['enrolled_at']=gmdate('c');
        unset($row['enrollment_verifier']);
        if (!$this->writeJson($path,$row)) return ['ok'=>false,'code'=>'EXPANSION_FINALIZE_WRITE_FAILED'];

        return [
            'ok'=>true,
            'code'=>'EXPANSION_ENROLL_ACCEPTED',
            'cell'=>$cell,
            'parent'=>[
                'cell_id'=>(string)$this->parent['cell_id'],
                'root_id'=>$rootId,
                'generation'=>$parentGeneration,
                'public_key'=>(string)$this->parent['public_key'],
                'base_url'=>rtrim((string)$this->parent['base_url'],'/'),
            ],
        ];
    }

    public function revokeCell(string $cellId, string $reason = 'operator revoke'): array
    {
        $cell = $this->readJson($this->cellPath($cellId));
        if ($cell === null) return ['ok'=>false,'code'=>'CELL_NOT_FOUND'];
        $cell['state']='revoked';
        $cell['revoked_at']=gmdate('c');
        $cell['revoke_reason']=mb_substr(trim($reason),0,200);
        return $this->writeJson($this->cellPath($cellId),$cell)
            ? ['ok'=>true,'code'=>'CELL_REVOKED','cell_id'=>$cellId]
            : ['ok'=>false,'code'=>'CELL_REVOKE_WRITE_FAILED'];
    }

    /** @return list<array<string,mixed>> */
    public function cells(): array
    {
        $out=[];
        foreach (glob($this->dir.'/cells/*.json') ?: [] as $file) {
            $row=$this->readJson($file);
            if ($row!==null) $out[]=$row;
        }
        usort($out, static fn(array $a,array $b): int => strcmp((string)($a['cell_id']??''),(string)($b['cell_id']??'')));
        return $out;
    }

    /** @return array<string,mixed> */
    private function transition(string $id, array $allowed, string $next): array
    {
        $path=$this->expansionPath($id);
        $row=$this->readJson($path);
        if ($row===null) return ['ok'=>false,'code'=>'EXPANSION_NOT_FOUND'];
        if (!in_array((string)($row['state']??''),$allowed,true)) return ['ok'=>false,'code'=>'EXPANSION_STATE_CONFLICT','state'=>$row['state']??null];
        if ((int)($row['expires_unix']??0)<time()) {
            $row['state']='expired';
            $this->writeJson($path,$row);
            return ['ok'=>false,'code'=>'EXPANSION_EXPIRED'];
        }
        $row['state']=$next;
        $row[$next.'_at']=gmdate('c');
        return $this->writeJson($path,$row)
            ? ['ok'=>true,'code'=>'EXPANSION_'.strtoupper($next),'expansion_id'=>$id,'state'=>$next]
            : ['ok'=>false,'code'=>'EXPANSION_STATE_WRITE_FAILED'];
    }

    private function expansionPath(string $id): string
    {
        if (!preg_match('/^exp-[a-f0-9]{24}$/',$id)) throw new InvalidArgumentException('EXPANSION_ID_INVALID');
        return $this->dir.'/expansions/'.$id.'.json';
    }

    private function cellPath(string $id): string
    {
        if (!preg_match('/^cell-[a-f0-9]{24}$/',$id)) throw new InvalidArgumentException('CELL_ID_INVALID');
        return $this->dir.'/cells/'.$id.'.json';
    }

    private static function normalizeRemoteRoot(string $path): ?string
    {
        $path=trim(str_replace('\\','/',$path));
        if ($path==='') $path='/';
        if ($path[0] !== '/') $path='/'.$path;
        $parts=[];
        foreach (explode('/',$path) as $part) {
            if ($part===''||$part==='.') continue;
            if ($part==='..'||str_contains($part,"\0")) return null;
            $parts[]=$part;
        }
        return '/'.implode('/',$parts);
    }

    /** @param mixed $caps @return list<string> */
    private static function normalizeCapabilities($caps): array
    {
        if (!is_array($caps)) return [];
        $out=[];
        foreach ($caps as $cap) {
            $cap=strtolower(trim((string)$cap));
            if ($cap!=='' && preg_match('/^[a-z0-9_.-]{1,64}$/',$cap)) $out[$cap]=true;
        }
        return array_keys($out);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('REGISTRY_STORAGE_UNAVAILABLE');
        @chmod($dir,0700);
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) return null;
        $raw=@file_get_contents($path);
        if (!is_string($raw)) return null;
        $row=json_decode($raw,true);
        return is_array($row)?$row:null;
    }

    /** @param array<string,mixed> $row */
    private function writeJson(string $path,array $row): bool
    {
        $json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return false;
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp,$json."\n",LOCK_EX)===false) return false;
        @chmod($tmp,0600);
        if (!@rename($tmp,$path)) { @unlink($tmp); return false; }
        @chmod($path,0600);
        return true;
    }
}
