<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionProtocol.php';
require_once __DIR__.'/CellLiving.php';
require_once __DIR__.'/CellPerceptionAction.php';

/**
 * Child-side federation state machine for an Expansion Cell.
 *
 * The child creates its own signing key locally. Its intrinsic living substrate
 * is materialized before node birth commits. Bootstrap enrollment material is
 * removed after activation. Only signed parent messages are accepted once active.
 */
final class KiComExpansionCellNode
{
    private string $dir;

    public function __construct(string $storageDir)
    {
        $this->dir=rtrim($storageDir,'/');
        if (!is_dir($this->dir) && !@mkdir($this->dir,0700,true) && !is_dir($this->dir)) {
            throw new RuntimeException('CELL_STORAGE_UNAVAILABLE');
        }
        @chmod($this->dir,0700);
    }

    /**
     * Called once after the complete package lands on the target host.
     *
     * Birth is fail-closed: the node is committed only after the daughter has
     * local memory, workspace, observer, genome/LKG, immune, evolution,
     * perception memory and action memory substrate. Enrollment later establishes
     * lineage/trust only.
     *
     * @param array<string,mixed> $bootstrap
     * @return array<string,mixed>
     */
    public function initialize(array $bootstrap): array
    {
        if (is_file($this->dir.'/node.json')) return ['ok'=>false,'code'=>'CELL_ALREADY_INITIALIZED'];
        $living=new KiComExpansionCellLiving($this->dir);
        $living->destroyIfUncommitted();

        foreach (['expansion_id','enrollment_token','base_url','parent_id','parent_public_key','parent_base_url'] as $key) {
            if (!isset($bootstrap[$key]) || !is_string($bootstrap[$key]) || trim($bootstrap[$key])==='') {
                return ['ok'=>false,'code'=>'CELL_BOOTSTRAP_INVALID'];
            }
        }
        if (!preg_match('/^exp-[a-f0-9]{24}$/',(string)$bootstrap['expansion_id'])) return ['ok'=>false,'code'=>'CELL_EXPANSION_ID_INVALID'];
        $parts=parse_url((string)$bootstrap['base_url']);
        if (!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])) return ['ok'=>false,'code'=>'CELL_BASE_URL_INVALID'];
        $parentKey=KiComExpansionProtocol::b64urlDecode((string)$bootstrap['parent_public_key']);
        if ($parentKey===null||strlen($parentKey)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return ['ok'=>false,'code'=>'CELL_PARENT_KEY_INVALID'];

        $identity=KiComExpansionProtocol::createIdentity();
        $secret=KiComExpansionProtocol::b64urlDecode($identity['secret_key']);
        if ($secret===null) return ['ok'=>false,'code'=>'CELL_IDENTITY_CREATE_FAILED'];

        $public=[
            'schema'=>1,
            'state'=>'enrolling',
            'cell_id'=>$identity['cell_id'],
            'public_key'=>$identity['public_key'],
            'base_url'=>rtrim((string)$bootstrap['base_url'],'/'),
            'capabilities'=>self::normalizeCapabilities($bootstrap['capabilities']??['federation.tick','status.report']),
            'created_at'=>gmdate('c'),
        ];

        $born=$living->initialize([
            'cell_id'=>$public['cell_id'],
            'base_url'=>$public['base_url'],
            'parent_id'=>(string)$bootstrap['parent_id'],
            'capabilities'=>$public['capabilities'],
        ]);
        if (empty($born['ok'])) {
            if (function_exists('sodium_memzero')) sodium_memzero($secret);
            return $born;
        }

        if (@file_put_contents($this->dir.'/signing.secret',$secret,LOCK_EX)===false) {
            if (function_exists('sodium_memzero')) sodium_memzero($secret);
            $this->rollbackBirth($living);
            return ['ok'=>false,'code'=>'CELL_SECRET_WRITE_FAILED'];
        }
        @chmod($this->dir.'/signing.secret',0600);
        if (function_exists('sodium_memzero')) sodium_memzero($secret);

        if (!$this->writeJson($this->dir.'/node.json',$public)) {
            $this->rollbackBirth($living);
            return ['ok'=>false,'code'=>'CELL_PUBLIC_WRITE_FAILED'];
        }

        $privateBootstrap=[
            'schema'=>1,
            'expansion_id'=>(string)$bootstrap['expansion_id'],
            'enrollment_token'=>(string)$bootstrap['enrollment_token'],
            'parent_id'=>(string)$bootstrap['parent_id'],
            'parent_public_key'=>(string)$bootstrap['parent_public_key'],
            'parent_base_url'=>rtrim((string)$bootstrap['parent_base_url'],'/'),
            'created_at'=>gmdate('c'),
        ];
        if (!$this->writeJson($this->dir.'/bootstrap.private.json',$privateBootstrap)) {
            $this->rollbackBirth($living);
            return ['ok'=>false,'code'=>'CELL_BOOTSTRAP_WRITE_FAILED'];
        }

        $paNode=$public+[
            'parent_id'=>(string)$bootstrap['parent_id'],
            'parent_base_url'=>rtrim((string)$bootstrap['parent_base_url'],'/'),
            'root_id'=>'',
            'generation'=>1,
        ];
        $pa=new KiComExpansionCellPerceptionAction($this->dir);
        $paReady=$pa->ensure($paNode);
        if(empty($paReady['ok'])){
            $this->rollbackBirth($living);
            return ['ok'=>false,'code'=>'CELL_PA_BIRTH_FAILED','perception_action'=>$paReady];
        }
        $paCycle=$pa->cycle($paNode,'birth');
        if(empty($paCycle['ok'])){
            $this->rollbackBirth($living);
            return ['ok'=>false,'code'=>'CELL_PA_BIRTH_CYCLE_FAILED','perception_action'=>$paCycle];
        }

        $livingStatus=$living->status();
        if (empty($livingStatus['living_ready'])) {
            $this->rollbackBirth($living);
            return ['ok'=>false,'code'=>'CELL_BIRTH_INCOMPLETE','living'=>$livingStatus];
        }

        return [
            'ok'=>true,
            'code'=>'CELL_INITIALIZED',
            'cell_id'=>$public['cell_id'],
            'base_url'=>$public['base_url'],
            'living_ready'=>true,
            'perception_action_ready'=>true,
            'intrinsic_subsystems'=>$livingStatus['subsystems']??[],
        ];
    }

    /** @return array<string,mixed> */
    public function enrollmentHello(): array
    {
        $node=$this->readJson($this->dir.'/node.json');
        $boot=$this->readJson($this->dir.'/bootstrap.private.json');
        if ($node===null||$boot===null||($node['state']??'')!=='enrolling') return ['ok'=>false,'code'=>'CELL_NOT_ENROLLING'];
        $living=(new KiComExpansionCellLiving($this->dir))->status();
        $pa=(new KiComExpansionCellPerceptionAction($this->dir))->status();
        if (empty($living['living_ready'])||empty($pa['ready'])) return ['ok'=>false,'code'=>'CELL_LIVING_NOT_READY','living'=>$living,'perception_action'=>$pa];
        $descriptor=[
            'cell_id'=>(string)$node['cell_id'],
            'public_key'=>(string)$node['public_key'],
            'base_url'=>(string)$node['base_url'],
            'capabilities'=>self::normalizeCapabilities($node['capabilities']??[]),
            'living_ready'=>true,
            'perception_action_ready'=>true,
        ];
        $proof=KiComExpansionProtocol::enrollmentProof($descriptor,(string)$boot['enrollment_token']);
        return [
            'ok'=>true,
            'code'=>'CELL_ENROLLMENT_HELLO',
            'expansion_id'=>(string)$boot['expansion_id'],
            'descriptor'=>$descriptor,
            'proof'=>$proof,
        ];
    }

    /**
     * Parent activation must be a signed envelope using the public key that was
     * embedded in the one-time bootstrap package.
     *
     * @param array<string,mixed> $envelope
     * @return array<string,mixed>
     */
    public function activate(array $envelope): array
    {
        $node=$this->readJson($this->dir.'/node.json');
        $boot=$this->readJson($this->dir.'/bootstrap.private.json');
        if ($node===null||$boot===null||($node['state']??'')!=='enrolling') return ['ok'=>false,'code'=>'CELL_NOT_ENROLLING'];
        $check=KiComExpansionProtocol::verifyEnvelope(
            $envelope,
            (string)$boot['parent_id'],
            (string)$node['cell_id'],
            (string)$boot['parent_public_key']
        );
        if (empty($check['ok'])) return $check;
        if (strtoupper((string)($envelope['operation']??''))!=='EXPANSION_ACTIVATE') return ['ok'=>false,'code'=>'CELL_ACTIVATION_OPERATION_INVALID'];
        $payload=$envelope['payload']??null;
        if (!is_array($payload)) return ['ok'=>false,'code'=>'CELL_ACTIVATION_PAYLOAD_INVALID'];
        foreach (['root_id','parent_id','generation'] as $key) if (!array_key_exists($key,$payload)) return ['ok'=>false,'code'=>'CELL_ACTIVATION_PAYLOAD_INVALID'];
        if (!hash_equals((string)$boot['parent_id'],(string)$payload['parent_id'])) return ['ok'=>false,'code'=>'CELL_PARENT_MISMATCH'];

        $before=$node;
        $node['state']='active';
        $node['root_id']=(string)$payload['root_id'];
        $node['parent_id']=(string)$payload['parent_id'];
        $node['generation']=max(1,(int)$payload['generation']);
        $node['parent_public_key']=(string)$boot['parent_public_key'];
        $node['parent_base_url']=(string)$boot['parent_base_url'];
        $node['activated_at']=gmdate('c');

        $pa=new KiComExpansionCellPerceptionAction($this->dir);
        $paCycle=$pa->cycle($node,'activation',['parent_verified'=>true,'message_id'=>(string)($envelope['message_id']??'')]);
        if(empty($paCycle['ok'])) return ['ok'=>false,'code'=>'CELL_PA_ACTIVATION_FAILED','perception_action'=>$paCycle];

        if (!$this->writeJson($this->dir.'/node.json',$node)) return ['ok'=>false,'code'=>'CELL_ACTIVATION_WRITE_FAILED'];

        $living=new KiComExpansionCellLiving($this->dir);
        if (!$living->recordActivation($node)) {
            $this->writeJson($this->dir.'/node.json',$before);
            return ['ok'=>false,'code'=>'CELL_LIVING_ACTIVATION_WRITE_FAILED'];
        }

        // Enrollment token is no longer needed after signed activation.
        @unlink($this->dir.'/bootstrap.private.json');
        return ['ok'=>true,'code'=>'CELL_ACTIVE','cell_id'=>(string)$node['cell_id'],'root_id'=>$node['root_id'],'parent_id'=>$node['parent_id'],'generation'=>$node['generation'],'living_ready'=>true,'perception_action_ready'=>true];
    }

    /**
     * Verify one parent cron/federation message and return a signed child reply.
     * FEDERATION_TICK runs the local immune doctor and the perception/action loop;
     * no parent capability is installed by the tick.
     *
     * @param array<string,mixed> $envelope
     * @return array<string,mixed>
     */
    public function handleParentMessage(array $envelope): array
    {
        $node=$this->readJson($this->dir.'/node.json');
        if ($node===null||($node['state']??'')!=='active') return ['ok'=>false,'code'=>'CELL_NOT_ACTIVE'];
        $check=KiComExpansionProtocol::verifyEnvelope(
            $envelope,
            (string)$node['parent_id'],
            (string)$node['cell_id'],
            (string)$node['parent_public_key']
        );
        if (empty($check['ok'])) return $check;
        $op=strtoupper((string)($envelope['operation']??''));
        if (!in_array($op,['FEDERATION_TICK','FEDERATION_STATUS_REQUEST'],true)) return ['ok'=>false,'code'=>'CELL_OPERATION_FORBIDDEN'];

        $doctor=(new KiComExpansionCellLiving($this->dir))->doctor($op==='FEDERATION_TICK');
        $pa=new KiComExpansionCellPerceptionAction($this->dir);
        $paCycle=$pa->cycle(
            $node,
            $op==='FEDERATION_TICK'?'federation_tick':'federation_status_request',
            ['parent_verified'=>true,'message_id'=>(string)($envelope['message_id']??'')]
        );
        $paStatus=$pa->status();
        $result=[
            'ok'=>true,
            'cell_id'=>(string)$node['cell_id'],
            'state'=>'active',
            'generation'=>(int)$node['generation'],
            'operation'=>$op,
            'received_message_id'=>(string)($envelope['message_id']??''),
            'time'=>gmdate('c'),
            'living_ready'=>!empty($doctor['status']['living_ready']),
            'living_drift_count'=>(int)($doctor['status']['drift_count']??0),
            'living_lkg_ok'=>!empty($doctor['status']['lkg_ok']),
            'perception_action_ready'=>!empty($paStatus['ready']),
            'perception_state'=>(string)($paStatus['perception_state']??'UNKNOWN'),
            'known_neighbors'=>(int)($paStatus['neighbors']??0),
            'known_boundaries'=>(int)($paStatus['boundaries']??0),
            'perception_cycle_ok'=>!empty($paCycle['ok']),
        ];
        $secret=@file_get_contents($this->dir.'/signing.secret');
        if (!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) return ['ok'=>false,'code'=>'CELL_SECRET_UNAVAILABLE'];
        $secretB64=KiComExpansionProtocol::b64urlEncode($secret);
        if (function_exists('sodium_memzero')) sodium_memzero($secret);
        try {
            return KiComExpansionProtocol::signEnvelope(
                (string)$node['cell_id'],
                (string)$node['parent_id'],
                $op.'_RESULT',
                $result,
                $secretB64
            );
        } finally {
            if (function_exists('sodium_memzero')) sodium_memzero($secretB64);
        }
    }

    /** @return array<string,mixed>|null */
    public function status(): ?array
    {
        $node=$this->readJson($this->dir.'/node.json');
        if ($node===null) return null;
        $living=(new KiComExpansionCellLiving($this->dir))->status();
        $pa=(new KiComExpansionCellPerceptionAction($this->dir))->status();
        $node['living_ready']=!empty($living['living_ready']);
        $node['living']=$living;
        $node['perception_action_ready']=!empty($pa['ready']);
        $node['perception_action']=$pa;
        return $node;
    }

    /** @param mixed $caps @return list<string> */
    private static function normalizeCapabilities($caps): array
    {
        if (!is_array($caps)) return [];
        $out=[];
        foreach ($caps as $cap) {
            $cap=strtolower(trim((string)$cap));
            if ($cap!==''&&preg_match('/^[a-z0-9_.-]{1,64}$/',$cap)) $out[$cap]=true;
        }
        return array_keys($out);
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

    private function rollbackBirth(KiComExpansionCellLiving $living): void
    {
        @unlink($this->dir.'/bootstrap.private.json');
        @unlink($this->dir.'/node.json');
        @unlink($this->dir.'/signing.secret');
        $living->destroyIfUncommitted();
    }
}
