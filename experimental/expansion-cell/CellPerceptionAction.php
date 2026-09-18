<?php
declare(strict_types=1);

/**
 * Persistent perception/action substrate for one daughter cell.
 *
 * This class does not grant authority. It records evidence about the cell,
 * its local territory, known peers, capabilities and boundaries. History is
 * append-only; current/model files are materialized views of that history.
 */
final class KiComExpansionCellPerceptionAction
{
    private string $storageDir;
    private string $rootDir;
    private string $livingDir;

    /** @var list<string> */
    private const STATES=['UNKNOWN','AVAILABLE','UNAVAILABLE','FORBIDDEN','DEGRADED','STALE'];

    public function __construct(string $storageDir)
    {
        $this->storageDir=rtrim($storageDir,'/');
        $this->rootDir=dirname($this->storageDir);
        $this->livingDir=$this->storageDir.'/living';
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    public function ensure(array $node): array
    {
        if(!is_dir($this->livingDir)) return ['ok'=>false,'code'=>'CELL_PA_LIVING_MISSING'];
        foreach(['perception','action'] as $rel){
            $dir=$this->livingDir.'/'.$rel;
            if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)) return ['ok'=>false,'code'=>'CELL_PA_DIR_CREATE_FAILED','path'=>$rel];
            @chmod($dir,0700);
        }

        foreach([
            'perception/history.jsonl','perception/changes.jsonl','action/history.jsonl'
        ] as $rel){
            $path=$this->livingDir.'/'.$rel;
            if(!is_file($path)&&!$this->atomicWrite($path,'',0600)) return ['ok'=>false,'code'=>'CELL_PA_HISTORY_CREATE_FAILED','path'=>$rel];
        }

        if(!is_file($this->livingDir.'/perception/neighbors.json')){
            $neighbors=$this->seedNeighbors($node);
            if(!$this->writeJson($this->livingDir.'/perception/neighbors.json',$neighbors)) return ['ok'=>false,'code'=>'CELL_PA_NEIGHBORS_CREATE_FAILED'];
        }
        if(!is_file($this->livingDir.'/perception/current.json')){
            $initial=$this->buildSnapshot($node,['trigger'=>'birth-seed']);
            if(!$this->writeJson($this->livingDir.'/perception/current.json',$initial)) return ['ok'=>false,'code'=>'CELL_PA_CURRENT_CREATE_FAILED'];
            if(!$this->appendJsonLine($this->livingDir.'/perception/history.jsonl',['ts'=>gmdate('c'),'type'=>'perception_snapshot','snapshot'=>$initial])) return ['ok'=>false,'code'=>'CELL_PA_HISTORY_SEED_FAILED'];
        }
        if(!is_file($this->livingDir.'/action/model.json')||!is_file($this->livingDir.'/action/boundaries.json')||!is_file($this->livingDir.'/action/expansion-opportunities.json')){
            $snapshot=$this->readJson($this->livingDir.'/perception/current.json')??$this->buildSnapshot($node,['trigger'=>'model-seed']);
            $model=$this->deriveActionModel($snapshot,$node);
            if(!$this->writeModelViews($model)) return ['ok'=>false,'code'=>'CELL_PA_MODEL_CREATE_FAILED'];
        }
        return $this->status();
    }

    /**
     * Run one bounded observe -> remember -> derive -> record -> observe cycle.
     * No external authority is gained and no arbitrary remote probe is issued.
     *
     * @param array<string,mixed> $node
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function cycle(array $node,string $trigger='manual',array $context=[]): array
    {
        $ready=$this->ensure($node);
        if(empty($ready['ok'])) return $ready;

        $context['trigger']=$trigger;
        $neighbors=$this->updateNeighbors($node,$context);
        if(empty($neighbors['ok'])) return $neighbors;

        $previous=$this->readJson($this->livingDir.'/perception/current.json');
        $snapshot=$this->buildSnapshot($node,$context);
        if(!$this->writeJson($this->livingDir.'/perception/current.json',$snapshot)) return ['ok'=>false,'code'=>'CELL_PA_CURRENT_WRITE_FAILED'];
        if(!$this->appendJsonLine($this->livingDir.'/perception/history.jsonl',['ts'=>gmdate('c'),'type'=>'perception_snapshot','trigger'=>$trigger,'snapshot'=>$snapshot])) return ['ok'=>false,'code'=>'CELL_PA_HISTORY_APPEND_FAILED'];

        $beforeHash=$previous===null?'NONE':$this->stableHash($previous);
        $afterHash=$this->stableHash($snapshot);
        if($beforeHash!==$afterHash){
            if(!$this->appendJsonLine($this->livingDir.'/perception/changes.jsonl',[
                'ts'=>gmdate('c'),'type'=>'perception_change','trigger'=>$trigger,
                'before_sha256'=>$beforeHash,'after_sha256'=>$afterHash,
                'summary'=>$this->changeSummary($previous,$snapshot),
            ])) return ['ok'=>false,'code'=>'CELL_PA_CHANGES_APPEND_FAILED'];
        }

        $model=$this->deriveActionModel($snapshot,$node);
        if(!$this->writeModelViews($model)) return ['ok'=>false,'code'=>'CELL_PA_MODEL_WRITE_FAILED'];

        $action=$this->recordAction(
            $trigger==='federation_tick'?'federation.tick.receive':'perception.refresh',
            $trigger==='federation_tick'?'parent':'self',
            'success',
            [
                'trigger'=>$trigger,
                'message_id'=>(string)($context['message_id']??''),
                'evidence'=>'local-cycle-completed',
            ]
        );
        if(empty($action['ok'])) return $action;

        $after=$this->buildSnapshot($node,['trigger'=>$trigger,'cycle_result'=>'success']);
        if(!$this->writeJson($this->livingDir.'/perception/current.json',$after)) return ['ok'=>false,'code'=>'CELL_PA_POST_ACTION_OBSERVE_FAILED'];

        $status=$this->status();
        return [
            'ok'=>!empty($status['ready']),
            'code'=>!empty($status['ready'])?'CELL_PA_CYCLE_OK':'CELL_PA_CYCLE_DEGRADED',
            'trigger'=>$trigger,
            'perception'=>$after,
            'action_model'=>$model,
            'status'=>$status,
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function recordAction(string $action,string $target,string $result,array $data=[]): array
    {
        $action=strtolower(trim($action));$target=trim($target);$result=strtolower(trim($result));
        if($action===''||!preg_match('/^[a-z0-9_.-]{1,96}$/',$action)) return ['ok'=>false,'code'=>'CELL_PA_ACTION_INVALID'];
        if($target===''||strlen($target)>160) return ['ok'=>false,'code'=>'CELL_PA_ACTION_TARGET_INVALID'];
        if(!in_array($result,['success','blocked','failed','unknown','degraded'],true)) return ['ok'=>false,'code'=>'CELL_PA_ACTION_RESULT_INVALID'];
        $row=[
            'ts'=>gmdate('c'),
            'action'=>$action,
            'target'=>$target,
            'result'=>$result,
            'data'=>$this->sanitizeData($data),
        ];
        if(!$this->appendJsonLine($this->livingDir.'/action/history.jsonl',$row)) return ['ok'=>false,'code'=>'CELL_PA_ACTION_HISTORY_FAILED'];
        return ['ok'=>true,'code'=>'CELL_PA_ACTION_RECORDED','entry'=>$row];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $current=$this->readJson($this->livingDir.'/perception/current.json');
        $neighbors=$this->readJson($this->livingDir.'/perception/neighbors.json');
        $model=$this->readJson($this->livingDir.'/action/model.json');
        $boundaries=$this->readJson($this->livingDir.'/action/boundaries.json');
        $opportunities=$this->readJson($this->livingDir.'/action/expansion-opportunities.json');
        $ready=$current!==null&&$neighbors!==null&&$model!==null&&$boundaries!==null&&$opportunities!==null
            &&is_file($this->livingDir.'/perception/history.jsonl')&&is_file($this->livingDir.'/perception/changes.jsonl')&&is_file($this->livingDir.'/action/history.jsonl');
        return [
            'ok'=>$ready,
            'code'=>$ready?'CELL_PA_READY':'CELL_PA_INCOMPLETE',
            'ready'=>$ready,
            'perception_state'=>(string)($current['overall_state']??'UNKNOWN'),
            'observed_at'=>(string)($current['observed_at']??''),
            'neighbors'=>is_array($neighbors['neighbors']??null)?count($neighbors['neighbors']):0,
            'actions'=>is_array($model['actions']??null)?count($model['actions']):0,
            'boundaries'=>is_array($boundaries['boundaries']??null)?count($boundaries['boundaries']):0,
            'expansion_opportunities'=>is_array($opportunities['opportunities']??null)?count($opportunities['opportunities']):0,
            'states'=>self::STATES,
        ];
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $context @return array<string,mixed> */
    private function buildSnapshot(array $node,array $context): array
    {
        $now=time();
        $neighbors=$this->readJson($this->livingDir.'/perception/neighbors.json')??$this->seedNeighbors($node);
        $resources=[
            'canonical_memory'=>$this->resourceState($this->livingDir.'/memory',true),
            'workspace'=>$this->resourceState($this->livingDir.'/workspace',true),
            'observer'=>$this->resourceState($this->livingDir.'/observer',true),
            'genome_lkg'=>$this->resourceState($this->livingDir.'/genome/lkg',false),
            'immune'=>$this->resourceState($this->livingDir.'/immune',false),
            'evolution'=>$this->resourceState($this->livingDir.'/evolution/candidates',true),
            'perception_memory'=>$this->resourceState($this->livingDir.'/perception',true),
            'action_memory'=>$this->resourceState($this->livingDir.'/action',true),
        ];
        $declared=[];
        foreach(($node['capabilities']??[]) as $cap){$c=strtolower(trim((string)$cap));if($c!=='')$declared[$c]=true;}
        $capabilities=[
            'perception.refresh'=>['state'=>'AVAILABLE','evidence'=>'local-runtime'],
            'perception.remember'=>['state'=>$resources['perception_memory']['state'],'evidence'=>'local-storage'],
            'action.remember'=>['state'=>$resources['action_memory']['state'],'evidence'=>'local-storage'],
            'status.report'=>['state'=>is_file($this->rootDir.'/status.php')?'AVAILABLE':'UNAVAILABLE','evidence'=>'runtime-file'],
            'immune.scan'=>['state'=>is_file($this->livingDir.'/immune/policy.json')?'AVAILABLE':'UNAVAILABLE','evidence'=>'local-policy'],
            'federation.tick'=>['state'=>isset($declared['federation.tick'])?$this->federationState($neighbors):'UNAVAILABLE','evidence'=>'declared-capability+peer-memory'],
            'internal.capability.extend'=>['state'=>is_dir($this->livingDir.'/evolution/candidates')?'AVAILABLE':'UNAVAILABLE','evidence'=>'candidate-substrate','mode'=>'candidate-only','promotion'=>'DEFERRED'],
        ];
        $overall='AVAILABLE';
        foreach($resources as $r){if(($r['state']??'UNKNOWN')==='DEGRADED')$overall='DEGRADED';if(($r['state']??'UNKNOWN')==='UNAVAILABLE'){$overall='DEGRADED';break;}}
        return [
            'schema'=>1,
            'observed_at'=>gmdate('c',$now),
            'expires_at'=>gmdate('c',$now+900),
            'source'=>['local-runtime','local-filesystem-metadata','signed-federation-memory'],
            'confidence'=>0.95,
            'sensitivity'=>'cell-internal',
            'overall_state'=>$overall,
            'self'=>[
                'cell_id'=>(string)($node['cell_id']??''),
                'state'=>(string)($node['state']??'unknown'),
                'generation'=>(int)($node['generation']??1),
                'base_url'=>(string)($node['base_url']??''),
                'parent_id'=>(string)($node['parent_id']??''),
                'root_id'=>(string)($node['root_id']??''),
                'location'=>[
                    'scheme'=>(string)(parse_url((string)($node['base_url']??''),PHP_URL_SCHEME)?:''),
                    'host'=>(string)(parse_url((string)($node['base_url']??''),PHP_URL_HOST)?:''),
                    'logical_territory'=>'cell-local-/kicom',
                ],
            ],
            'environment'=>[
                'php_version'=>PHP_VERSION,
                'sapi'=>PHP_SAPI,
                'local_storage'=>is_dir($this->storageDir)?'AVAILABLE':'UNAVAILABLE',
                'local_storage_writable'=>is_writable($this->storageDir),
                'arbitrary_filesystem'=>'FORBIDDEN',
                'arbitrary_remote_fetch'=>'FORBIDDEN',
            ],
            'resources'=>$resources,
            'capabilities'=>$capabilities,
            'neighbors'=>$neighbors['neighbors']??[],
            'unknowns'=>[
                ['subject'=>'unprobed_external_systems','state'=>'UNKNOWN','reason'=>'no arbitrary remote probing'],
                ['subject'=>'undeclared_peer_capabilities','state'=>'UNKNOWN','reason'=>'only signed/evidenced peer data is trusted'],
            ],
            'trigger'=>(string)($context['trigger']??'manual'),
        ];
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $node @return array<string,mixed> */
    private function deriveActionModel(array $snapshot,array $node): array
    {
        $caps=is_array($snapshot['capabilities']??null)?$snapshot['capabilities']:[];
        $actions=[
            ['id'=>'perception.refresh','state'=>'AVAILABLE','authority'=>'cell-internal','preconditions'=>[],'effect'=>'refresh local environment/self model'],
            ['id'=>'perception.remember','state'=>(string)($caps['perception.remember']['state']??'UNKNOWN'),'authority'=>'cell-internal','preconditions'=>['perception storage writable'],'effect'=>'append observation history'],
            ['id'=>'action.remember','state'=>(string)($caps['action.remember']['state']??'UNKNOWN'),'authority'=>'cell-internal','preconditions'=>['action storage writable'],'effect'=>'append action/result history'],
            ['id'=>'federation.tick.receive','state'=>(string)($caps['federation.tick']['state']??'UNKNOWN'),'authority'=>'signed-parent-only','preconditions'=>['valid parent signature'],'effect'=>'refresh peer/environment evidence'],
            ['id'=>'immune.scan','state'=>(string)($caps['immune.scan']['state']??'UNKNOWN'),'authority'=>'cell-internal','preconditions'=>['local genome/LKG'],'effect'=>'detect managed runtime drift'],
            ['id'=>'internal.capability.extend','state'=>(string)($caps['internal.capability.extend']['state']??'UNKNOWN'),'authority'=>'cell-internal-candidate','preconditions'=>['deterministic fitness','local LKG rollback'],'effect'=>'create/test inert capability candidate','promotion'=>'DEFERRED'],
            ['id'=>'protected.external.write','state'=>'FORBIDDEN','authority'=>'protected-external-boundary','preconditions'=>['explicit external authorization/credential when required'],'effect'=>'external mutation'],
            ['id'=>'arbitrary.filesystem','state'=>'FORBIDDEN','authority'=>'none','preconditions'=>[],'effect'=>'none'],
            ['id'=>'arbitrary.remote.fetch','state'=>'FORBIDDEN','authority'=>'none','preconditions'=>[],'effect'=>'none'],
        ];
        $boundaries=[
            ['id'=>'arbitrary_filesystem','state'=>'FORBIDDEN','extendable'=>false,'reason'=>'cell authority is bounded to managed territory'],
            ['id'=>'arbitrary_remote_fetch','state'=>'FORBIDDEN','extendable'=>false,'reason'=>'remote access requires explicit allowlisted transport/capability'],
            ['id'=>'protected_external_system','state'=>'FORBIDDEN','extendable'=>true,'extension'=>'human authorization/credential only when external system requires it'],
            ['id'=>'missing_internal_software','state'=>'DEGRADED','extendable'=>true,'extension'=>'build/test inert internal candidate; promotion remains deferred'],
            ['id'=>'unknown_environment','state'=>'UNKNOWN','extendable'=>true,'extension'=>'add bounded observation source before acting'],
        ];
        $opportunities=[
            ['id'=>'observe-before-assume','boundary'=>'unknown_environment','state'=>'AVAILABLE','method'=>'bounded local/signed observation'],
            ['id'=>'internal-adapter-candidate','boundary'=>'missing_internal_software','state'=>'AVAILABLE','method'=>'candidate + deterministic static fitness','promotion'=>'DEFERRED'],
            ['id'=>'neighbor-delegation','boundary'=>'missing_internal_software','state'=>$this->hasAvailableNeighbor($snapshot)?'AVAILABLE':'UNKNOWN','method'=>'signed federation only after peer capability evidence'],
            ['id'=>'protected-external-request','boundary'=>'protected_external_system','state'=>'FORBIDDEN','method'=>'bundle human authorization requests only when necessary'],
        ];
        return [
            'schema'=>1,'updated_at'=>gmdate('c'),'cell_id'=>(string)($node['cell_id']??''),
            'actions'=>$actions,'boundaries'=>$boundaries,'expansion_opportunities'=>$opportunities,
            'rule'=>'Capabilities describe evidence, not permission. Memory/goals never grant runtime authority.',
        ];
    }

    /** @param array<string,mixed> $model */
    private function writeModelViews(array $model): bool
    {
        if(!$this->writeJson($this->livingDir.'/action/model.json',$model)) return false;
        if(!$this->writeJson($this->livingDir.'/action/boundaries.json',['schema'=>1,'updated_at'=>gmdate('c'),'boundaries'=>$model['boundaries']??[]])) return false;
        if(!$this->writeJson($this->livingDir.'/action/expansion-opportunities.json',['schema'=>1,'updated_at'=>gmdate('c'),'opportunities'=>$model['expansion_opportunities']??[]])) return false;
        return true;
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $context @return array<string,mixed> */
    private function updateNeighbors(array $node,array $context): array
    {
        $state=$this->readJson($this->livingDir.'/perception/neighbors.json')??$this->seedNeighbors($node);
        $rows=is_array($state['neighbors']??null)?$state['neighbors']:[];
        $parentId=(string)($node['parent_id']??'');
        $found=false;
        foreach($rows as &$row){
            if(!is_array($row)||($row['cell_id']??'')!==$parentId) continue;
            $found=true;
            if(!empty($context['parent_verified'])){
                $row['state']='AVAILABLE';$row['last_seen']=gmdate('c');$row['evidence']='verified-signed-parent-message';$row['confidence']=1.0;
            } elseif(isset($row['last_seen'])&&is_string($row['last_seen'])) {
                $age=time()-(int)(strtotime($row['last_seen'])?:0);if($age>1800)$row['state']='STALE';
            }
        }
        unset($row);
        if(!$found&&$parentId!=='') $rows[]=$this->parentNeighbor($node);
        $state=['schema'=>1,'updated_at'=>gmdate('c'),'neighbors'=>$rows];
        if(!$this->writeJson($this->livingDir.'/perception/neighbors.json',$state)) return ['ok'=>false,'code'=>'CELL_PA_NEIGHBORS_WRITE_FAILED'];
        return ['ok'=>true,'code'=>'CELL_PA_NEIGHBORS_UPDATED','neighbors'=>$rows];
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function seedNeighbors(array $node): array
    {
        $rows=[];$parent=(string)($node['parent_id']??'');
        if($parent!=='')$rows[]=$this->parentNeighbor($node);
        $root=(string)($node['root_id']??'');
        if($root!==''&&$root!==$parent)$rows[]=['cell_id'=>$root,'role'=>'root-lineage','state'=>'UNKNOWN','base_url'=>null,'last_seen'=>null,'evidence'=>'lineage-only','confidence'=>0.5];
        return ['schema'=>1,'updated_at'=>gmdate('c'),'neighbors'=>$rows];
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function parentNeighbor(array $node): array
    {
        return [
            'cell_id'=>(string)($node['parent_id']??''),'role'=>'parent','state'=>'UNKNOWN',
            'base_url'=>(string)($node['parent_base_url']??''),'last_seen'=>null,
            'evidence'=>'configured-lineage','confidence'=>0.75,
        ];
    }

    /** @return array{state:string,writable:bool,observed_at:string} */
    private function resourceState(string $path,bool $needsWrite): array
    {
        if(!file_exists($path)) return ['state'=>'UNAVAILABLE','writable'=>false,'observed_at'=>gmdate('c')];
        $w=is_writable($path);$state=($needsWrite&&!$w)?'DEGRADED':'AVAILABLE';
        return ['state'=>$state,'writable'=>$w,'observed_at'=>gmdate('c')];
    }

    /** @param array<string,mixed> $neighbors */
    private function federationState(array $neighbors): string
    {
        foreach(($neighbors['neighbors']??[]) as $n)if(is_array($n)&&($n['role']??'')==='parent')return in_array(($n['state']??'UNKNOWN'),['AVAILABLE','STALE'],true)?(string)$n['state']:'UNKNOWN';
        return 'UNKNOWN';
    }

    /** @param array<string,mixed> $snapshot */
    private function hasAvailableNeighbor(array $snapshot): bool
    {
        foreach(($snapshot['neighbors']??[]) as $n)if(is_array($n)&&($n['state']??'')==='AVAILABLE')return true;
        return false;
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after @return array<string,mixed> */
    private function changeSummary(?array $before,array $after): array
    {
        if($before===null)return ['kind'=>'initial'];
        return [
            'overall_before'=>(string)($before['overall_state']??'UNKNOWN'),
            'overall_after'=>(string)($after['overall_state']??'UNKNOWN'),
            'neighbors_before'=>is_array($before['neighbors']??null)?count($before['neighbors']):0,
            'neighbors_after'=>is_array($after['neighbors']??null)?count($after['neighbors']):0,
        ];
    }

    /** @param array<string,mixed> $row */
    private function stableHash(array $row): string
    {
        unset($row['observed_at'],$row['expires_at'],$row['trigger']);
        $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return hash('sha256',is_string($json)?$json:'');
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function sanitizeData(array $data): array
    {
        $out=[];foreach($data as $k=>$v){$key=(string)$k;if(strlen($key)>64)continue;if(is_scalar($v)||$v===null)$out[$key]=is_string($v)?substr($v,0,240):$v;}
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if(!is_file($path))return null;$raw=@file_get_contents($path);if(!is_string($raw))return null;$j=json_decode($raw,true);return is_array($j)?$j:null;
    }

    /** @param array<string,mixed> $row */
    private function writeJson(string $path,array $row): bool
    {
        $json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return is_string($json)&&$this->atomicWrite($path,$json."\n",0600);
    }

    /** @param array<string,mixed> $row */
    private function appendJsonLine(string $path,array $row): bool
    {
        $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($json))return false;
        $dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return false;
        $ok=@file_put_contents($path,$json."\n",FILE_APPEND|LOCK_EX)!==false;if($ok)@chmod($path,0600);return $ok;
    }

    private function atomicWrite(string $path,string $content,int $mode): bool
    {
        $dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return false;
        try{$suffix=bin2hex(random_bytes(4));}catch(Throwable $e){$suffix=substr(hash('sha256',uniqid('',true)),0,8);}
        $tmp=$path.'.tmp.'.$suffix;if(@file_put_contents($tmp,$content,LOCK_EX)===false)return false;@chmod($tmp,$mode);
        if(!@rename($tmp,$path)){@unlink($tmp);return false;}@chmod($path,$mode);return true;
    }
}
