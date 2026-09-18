<?php
declare(strict_types=1);

/**
 * Read-only, sandbox-bound perception bridge for the DEV UI.
 *
 * The browser never talks directly to the child's private world-model files.
 * This bridge signs PERCEPTION_QUERY with the existing parent federation
 * identity, verifies the child's Ed25519 reply and returns only the bounded
 * world report produced by CellWorldModel::report().
 */
final class KiComDevSandboxPerception
{
    private const RESOURCE_ALIAS='sandbox';
    private const TARGET_BASE_URL='https://sandbox.rurtalbahn.info';

    private string $sourceDir;
    private string $parentBaseUrl;

    public function __construct(string $sourceDir,string $parentBaseUrl)
    {
        $this->sourceDir=rtrim($sourceDir,'/');
        $this->parentBaseUrl=rtrim($parentBaseUrl,'/');
        if(!is_file($this->sourceDir.'/ExpansionService.php')) throw new RuntimeException('DEV_PERCEPTION_SOURCE_MISSING');
        $p=parse_url($this->parentBaseUrl);
        if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])) throw new RuntimeException('DEV_PERCEPTION_PARENT_URL_INVALID');
        require_once $this->sourceDir.'/ExpansionService.php';
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        if(!function_exists('kicomVarDir')) return ['ok'=>false,'code'=>'DEV_PERCEPTION_KICOM_RUNTIME_MISSING'];

        $service=new KiComExpansionService(
            kicomVarDir().'/expansion_federation',
            $this->sourceDir,
            $this->parentBaseUrl
        );
        $resource=$service->deploymentResourceStatus(self::RESOURCE_ALIAS);
        if(empty($resource['ok'])) return $resource;
        $descriptor=is_array($resource['resource']??null)?(array)$resource['resource']:[];
        if(($descriptor['class']??'')!=='test') return ['ok'=>false,'code'=>'DEV_PERCEPTION_SANDBOX_NOT_TEST_RESOURCE'];

        $state=$service->status();
        if(empty($state['ok'])) return $state;
        $parent=is_array($state['parent']??null)?(array)$state['parent']:[];
        $cells=is_array($state['cells']??null)?(array)$state['cells']:[];
        $childBase=self::TARGET_BASE_URL.'/kicom';
        $cell=null;
        foreach($cells as $row){
            if(!is_array($row)||($row['state']??'')!=='active') continue;
            if(hash_equals($childBase,rtrim((string)($row['base_url']??''),'/'))){$cell=$row;break;}
        }
        if(!is_array($cell)) return ['ok'=>false,'code'=>'DEV_PERCEPTION_SANDBOX_CELL_NOT_REGISTERED'];
        if(!preg_match('/^cell-[a-f0-9]{24}$/',(string)($cell['cell_id']??''))) return ['ok'=>false,'code'=>'DEV_PERCEPTION_CELL_ID_INVALID'];
        $childPublic=(string)($cell['public_key']??'');
        $pk=KiComExpansionProtocol::b64urlDecode($childPublic);
        if($pk===null||strlen($pk)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return ['ok'=>false,'code'=>'DEV_PERCEPTION_CELL_KEY_INVALID'];

        $identity=new KiComExpansionParentIdentity(kicomVarDir().'/expansion_federation/identity',$this->parentBaseUrl);
        $ready=$identity->ensure();
        if(empty($ready['ok'])) return $ready;
        $identityParent=is_array($ready['parent']??null)?(array)$ready['parent']:[];
        if(!hash_equals((string)($parent['cell_id']??''),(string)($identityParent['cell_id']??''))
            ||!hash_equals((string)($parent['public_key']??''),(string)($identityParent['public_key']??''))){
            return ['ok'=>false,'code'=>'DEV_PERCEPTION_PARENT_IDENTITY_MISMATCH'];
        }

        $secret=$identity->secretKeyB64();
        try{
            $peerRows=[];
            foreach($cells as $row){
                if(!is_array($row)||($row['state']??'')!=='active') continue;
                $id=(string)($row['cell_id']??'');
                if($id===''||$id===(string)$cell['cell_id']||!preg_match('/^cell-[a-f0-9]{24}$/',$id)) continue;
                $base=rtrim((string)($row['base_url']??''),'/');
                $u=parse_url($base);
                if(!is_array($u)||strtolower((string)($u['scheme']??''))!=='https'||empty($u['host'])) continue;
                $caps=[];
                foreach((is_array($row['capabilities']??null)?$row['capabilities']:[]) as $cap){
                    $cap=strtolower(trim((string)$cap));
                    if($cap!==''&&preg_match('/^[a-z0-9_.-]{1,64}$/',$cap))$caps[$cap]=true;
                }
                $peerRows[]=[
                    'cell_id'=>$id,
                    'base_url'=>$base,
                    'generation'=>max(0,(int)($row['generation']??0)),
                    'capabilities'=>array_keys($caps),
                ];
                if(count($peerRows)>=16) break;
            }

            $parentCaps=[];
            foreach((is_array($parent['capabilities']??null)?$parent['capabilities']:[]) as $cap){
                $cap=strtolower(trim((string)$cap));
                if($cap!==''&&preg_match('/^[a-z0-9_.-]{1,64}$/',$cap))$parentCaps[$cap]=true;
            }
            $peerContext=[
                'parent'=>[
                    'cell_id'=>(string)$parent['cell_id'],
                    'base_url'=>rtrim((string)$parent['base_url'],'/'),
                    'generation'=>max(0,(int)($parent['generation']??0)),
                    'capabilities'=>array_keys($parentCaps),
                ],
                'peers'=>$peerRows,
            ];

            $request=KiComExpansionProtocol::signEnvelope(
                (string)$parent['cell_id'],
                (string)$cell['cell_id'],
                'PERCEPTION_QUERY',
                [
                    'request_id'=>KiComExpansionProtocol::randomId('perception-'),
                    'requested_at'=>gmdate('c'),
                    'peer_context'=>$peerContext,
                ],
                $secret
            );
            $http=new KiComExpansionHttpsTransport($childBase);
            $reply=$http->postJson($childBase.'/federation.php',$request);
            $check=KiComExpansionProtocol::verifyEnvelope(
                $reply,
                (string)$cell['cell_id'],
                (string)$parent['cell_id'],
                $childPublic
            );
            if(empty($check['ok'])) return ['ok'=>false,'code'=>'DEV_PERCEPTION_REPLY_VERIFY_FAILED','detail'=>(string)($check['code']??'UNKNOWN')];
            if(strtoupper((string)($reply['operation']??''))!=='PERCEPTION_QUERY_RESULT') return ['ok'=>false,'code'=>'DEV_PERCEPTION_REPLY_OPERATION_INVALID'];
            $payload=is_array($reply['payload']??null)?(array)$reply['payload']:[];
            $report=is_array($payload['world_report']??null)?(array)$payload['world_report']:[];
            if(empty($report['ok'])||($report['code']??'')!=='CELL_WORLD_REPORT') return ['ok'=>false,'code'=>'DEV_PERCEPTION_WORLD_REPORT_INVALID'];

            return [
                'ok'=>true,
                'code'=>'DEV_SANDBOX_PERCEPTION_OK',
                'cell'=>[
                    'cell_id'=>(string)$cell['cell_id'],
                    'base_url'=>$childBase,
                    'generation'=>(int)($cell['generation']??0),
                ],
                'report'=>$report,
                'proof'=>[
                    'signed'=>true,
                    'operation'=>'PERCEPTION_QUERY_RESULT',
                    'message_id'=>(string)($reply['message_id']??''),
                    'verified_at'=>gmdate('c'),
                ],
            ];
        }finally{
            if(function_exists('sodium_memzero')) sodium_memzero($secret); else $secret='';
        }
    }
}
