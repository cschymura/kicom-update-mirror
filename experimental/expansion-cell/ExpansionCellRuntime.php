<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionProtocol.php';

/**
 * Child-local KiCom federation runtime.
 *
 * The node creates and persists its own Ed25519 identity locally. Parent
 * private material is never present. This class contains no deployment or
 * production mutation authority.
 */
final class KiComExpansionCellRuntime
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        $this->ensureDir($this->dir);
    }

    /** @param list<string> $capabilities */
    public function boot(string $baseUrl, array $capabilities = []): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $p = parse_url($baseUrl);
        if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https' || empty($p['host'])) {
            return ['ok'=>false,'code'=>'CELL_BASE_URL_HTTPS_REQUIRED'];
        }

        $identity = $this->readJson($this->identityPath());
        if ($identity === null) {
            $keys = KiComExpansionProtocol::createIdentity();
            $identity = [
                'schema'=>1,
                'cell_id'=>$keys['cell_id'],
                'public_key'=>$keys['public_key'],
                'secret_key'=>$keys['secret_key'],
                'base_url'=>$baseUrl,
                'state'=>'unjoined',
                'capabilities'=>$this->normalizeCapabilities($capabilities),
                'created_at'=>gmdate('c'),
                'root_id'=>null,
                'parent_id'=>null,
                'generation'=>null,
            ];
            if (!$this->writeJson($this->identityPath(), $identity)) {
                return ['ok'=>false,'code'=>'CELL_IDENTITY_WRITE_FAILED'];
            }
        } elseif (!hash_equals((string)($identity['base_url'] ?? ''), $baseUrl)) {
            return ['ok'=>false,'code'=>'CELL_BASE_URL_CONFLICT'];
        }

        return ['ok'=>true,'code'=>'CELL_READY','cell'=>$this->publicDescriptor($identity)];
    }

    public function status(): array
    {
        $identity = $this->readJson($this->identityPath());
        if ($identity === null) return ['ok'=>false,'code'=>'CELL_NOT_BOOTED'];
        return ['ok'=>true,'code'=>'CELL_STATUS','cell'=>$this->publicDescriptor($identity)];
    }

    /** @param array<string,mixed> $parentDescriptor */
    public function createEnrollmentRequest(string $token, array $parentDescriptor): array
    {
        $identity = $this->readJson($this->identityPath());
        if ($identity === null) return ['ok'=>false,'code'=>'CELL_NOT_BOOTED'];
        if (($identity['state'] ?? '') === 'active') return ['ok'=>false,'code'=>'CELL_ALREADY_ACTIVE'];

        foreach (['cell_id','public_key','base_url'] as $key) {
            if (!isset($parentDescriptor[$key]) || !is_string($parentDescriptor[$key]) || trim($parentDescriptor[$key]) === '') {
                return ['ok'=>false,'code'=>'PARENT_DESCRIPTOR_INVALID'];
            }
        }
        $pk = KiComExpansionProtocol::b64urlDecode((string)$parentDescriptor['public_key']);
        if ($pk === null || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ['ok'=>false,'code'=>'PARENT_PUBLIC_KEY_INVALID'];
        }
        if (strlen($token) < 32 || strlen($token) > 128) return ['ok'=>false,'code'=>'ENROLLMENT_TOKEN_INVALID'];

        $descriptor = $this->publicDescriptor($identity);
        $proof = KiComExpansionProtocol::enrollmentProof($descriptor, $token);

        // Persist only public pending-parent information, never the token.
        $identity['state']='enrolling';
        $identity['pending_parent']=[
            'cell_id'=>(string)$parentDescriptor['cell_id'],
            'public_key'=>(string)$parentDescriptor['public_key'],
            'base_url'=>rtrim((string)$parentDescriptor['base_url'],'/'),
        ];
        $identity['enrollment_started_at']=gmdate('c');
        if (!$this->writeJson($this->identityPath(), $identity)) {
            return ['ok'=>false,'code'=>'CELL_ENROLLMENT_STATE_WRITE_FAILED'];
        }

        return [
            'ok'=>true,
            'code'=>'CELL_ENROLLMENT_REQUEST',
            'descriptor'=>$descriptor,
            'proof'=>$proof,
        ];
    }

    /** @param array<string,mixed> $parentDescriptor */
    public function activate(array $parentDescriptor, string $rootId, int $generation): array
    {
        $identity = $this->readJson($this->identityPath());
        if ($identity === null) return ['ok'=>false,'code'=>'CELL_NOT_BOOTED'];
        if (($identity['state'] ?? '') !== 'enrolling') return ['ok'=>false,'code'=>'CELL_NOT_ENROLLING'];

        $pending = $identity['pending_parent'] ?? null;
        if (!is_array($pending)) return ['ok'=>false,'code'=>'CELL_PENDING_PARENT_MISSING'];
        foreach (['cell_id','public_key','base_url'] as $key) {
            if (!isset($parentDescriptor[$key]) || !hash_equals((string)$pending[$key], rtrim((string)$parentDescriptor[$key], $key==='base_url' ? '/' : ""))) {
                return ['ok'=>false,'code'=>'CELL_PARENT_ACTIVATION_MISMATCH'];
            }
        }
        if (!preg_match('/^cell-[a-f0-9]{24}$/', $rootId)) return ['ok'=>false,'code'=>'CELL_ROOT_ID_INVALID'];
        if ($generation < 1 || $generation > 64) return ['ok'=>false,'code'=>'CELL_GENERATION_INVALID'];

        $identity['state']='active';
        $identity['root_id']=$rootId;
        $identity['parent_id']=(string)$parentDescriptor['cell_id'];
        $identity['parent_public_key']=(string)$parentDescriptor['public_key'];
        $identity['parent_base_url']=rtrim((string)$parentDescriptor['base_url'],'/');
        $identity['generation']=$generation;
        $identity['activated_at']=gmdate('c');
        unset($identity['pending_parent']);
        if (!$this->writeJson($this->identityPath(), $identity)) return ['ok'=>false,'code'=>'CELL_ACTIVATION_WRITE_FAILED'];
        return ['ok'=>true,'code'=>'CELL_ACTIVE','cell'=>$this->publicDescriptor($identity)];
    }

    /** @param array<string,mixed> $envelope */
    public function verifyParentEnvelope(array $envelope): array
    {
        $identity = $this->readJson($this->identityPath());
        if ($identity === null || ($identity['state'] ?? '') !== 'active') return ['ok'=>false,'code'=>'CELL_NOT_ACTIVE'];

        $verified = KiComExpansionProtocol::verifyEnvelope(
            $envelope,
            (string)$identity['parent_id'],
            (string)$identity['cell_id'],
            (string)$identity['parent_public_key']
        );
        if (empty($verified['ok'])) return $verified;

        $messageId = (string)($envelope['message_id'] ?? '');
        if (!preg_match('/^msg-[a-f0-9]{24}$/', $messageId)) return ['ok'=>false,'code'=>'FEDERATION_MESSAGE_ID_INVALID'];
        if (!$this->rememberMessage($messageId)) return ['ok'=>false,'code'=>'FEDERATION_REPLAY_REJECTED'];
        return ['ok'=>true,'code'=>'FEDERATION_PARENT_MESSAGE_OK','payload'=>$envelope['payload'] ?? [],'operation'=>(string)($envelope['operation'] ?? '')];
    }

    /** @param array<string,mixed> $payload */
    public function signToParent(string $operation, array $payload): array
    {
        $identity = $this->readJson($this->identityPath());
        if ($identity === null || ($identity['state'] ?? '') !== 'active') throw new RuntimeException('CELL_NOT_ACTIVE');
        return KiComExpansionProtocol::signEnvelope(
            (string)$identity['cell_id'],
            (string)$identity['parent_id'],
            $operation,
            $payload,
            (string)$identity['secret_key']
        );
    }

    /** @param array<string,mixed> $envelope */
    public function acceptTick(array $envelope): array
    {
        $v = $this->verifyParentEnvelope($envelope);
        if (empty($v['ok'])) return $v;
        if (strtoupper((string)($v['operation'] ?? '')) !== 'FEDERATION_TICK') return ['ok'=>false,'code'=>'FEDERATION_OPERATION_NOT_TICK'];

        $identity = $this->readJson($this->identityPath());
        if ($identity === null) return ['ok'=>false,'code'=>'CELL_NOT_BOOTED'];
        $identity['last_tick_at']=gmdate('c');
        $identity['last_parent_message_id']=(string)($envelope['message_id'] ?? '');
        if (!$this->writeJson($this->identityPath(), $identity)) return ['ok'=>false,'code'=>'CELL_TICK_STATE_WRITE_FAILED'];

        $result = [
            'status'=>'ok',
            'received_message_id'=>(string)($envelope['message_id'] ?? ''),
            'cell_state'=>'active',
            'handled_at'=>gmdate('c'),
        ];
        return ['ok'=>true,'code'=>'FEDERATION_TICK_ACCEPTED','envelope'=>$this->signToParent('FEDERATION_TICK_RESULT',$result)];
    }

    /** @param array<string,mixed> $identity */
    private function publicDescriptor(array $identity): array
    {
        return [
            'cell_id'=>(string)($identity['cell_id'] ?? ''),
            'root_id'=>$identity['root_id'] ?? null,
            'parent_id'=>$identity['parent_id'] ?? null,
            'generation'=>$identity['generation'] ?? null,
            'public_key'=>(string)($identity['public_key'] ?? ''),
            'base_url'=>(string)($identity['base_url'] ?? ''),
            'state'=>(string)($identity['state'] ?? 'unknown'),
            'capabilities'=>$identity['capabilities'] ?? [],
            'created_at'=>(string)($identity['created_at'] ?? ''),
            'activated_at'=>$identity['activated_at'] ?? null,
            'last_tick_at'=>$identity['last_tick_at'] ?? null,
        ];
    }

    private function rememberMessage(string $messageId): bool
    {
        $path=$this->dir.'/seen.json';
        $rows=$this->readJson($path) ?? [];
        $now=time();
        foreach ($rows as $id=>$ts) {
            if (!is_int($ts) || $ts < $now-3600) unset($rows[$id]);
        }
        if (isset($rows[$messageId])) return false;
        $rows[$messageId]=$now;
        if (count($rows)>256) {
            asort($rows,SORT_NUMERIC);
            $rows=array_slice($rows,-256,null,true);
        }
        return $this->writeJson($path,$rows);
    }

    /** @param list<string> $caps @return list<string> */
    private function normalizeCapabilities(array $caps): array
    {
        $out=[];
        foreach ($caps as $cap) {
            $cap=strtolower(trim((string)$cap));
            if ($cap!=='' && preg_match('/^[a-z0-9_.-]{1,64}$/',$cap)) $out[$cap]=true;
        }
        return array_keys($out);
    }

    private function identityPath(): string { return $this->dir.'/identity.json'; }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('CELL_STORAGE_UNAVAILABLE');
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
