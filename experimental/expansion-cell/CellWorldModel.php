<?php
declare(strict_types=1);

/**
 * Situational world model built from the cell's local Perception/Action views.
 *
 * It never grants authority and never probes arbitrary remote systems. It turns
 * local evidence and signed parent-provided peer context into a persistent,
 * explicitly uncertain model of self, territory, access, neighbours, actions
 * and boundaries. Current views are materialized from append-only histories.
 */
final class KiComExpansionCellWorldModel
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
        $s=$this->ensureScaffold($node);
        if(empty($s['ok'])) return $s;
        if(!is_file($this->livingDir.'/perception/world.json')) return $this->refresh($node,'world-model-birth');
        return $this->status();
    }

    /**
     * Refresh the bounded world model. Signed peer context is accepted only when
     * the caller proves it came from an already-verified parent message.
     *
     * @param array<string,mixed> $node
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function refresh(array $node,string $trigger='manual',array $context=[]): array
    {
        $s=$this->ensureScaffold($node);
        if(empty($s['ok'])) return $s;

        if(!empty($context['parent_verified'])){
            $peers=$this->rememberParentEvidence($node,is_array($context['peer_context']??null)?(array)$context['peer_context']:[]);
            if(empty($peers['ok'])) return $peers;
        } else {
            $this->agePeerMemory();
        }

        $perception=$this->readJson($this->livingDir.'/perception/current.json')??[];
        $actions=$this->readJson($this->livingDir.'/action/model.json')??[];
        $boundaries=$this->readJson($this->livingDir.'/action/boundaries.json')??[];
        $opportunities=$this->readJson($this->livingDir.'/action/expansion-opportunities.json')??[];
        $peerMemory=$this->readJson($this->livingDir.'/perception/peer-memory.json')??['peers'=>[]];
        $lastAction=$this->lastJsonLine($this->livingDir.'/action/history.jsonl');

        $now=time();
        $perceptionExpiry=(int)(strtotime((string)($perception['expires_at']??''))?:0);
        $freshness=$perceptionExpiry>0&&$perceptionExpiry<$now?'STALE':(string)($perception['overall_state']??'UNKNOWN');
        if(!in_array($freshness,self::STATES,true)) $freshness='UNKNOWN';

        $access=$this->accessMap();
        $knownCaps=is_array($perception['capabilities']??null)?$perception['capabilities']:[];
        $peers=is_array($peerMemory['peers']??null)?$peerMemory['peers']:[];
        $unknowns=is_array($perception['unknowns']??null)?$perception['unknowns']:[];
        $unknowns[]=['subject'=>'unsolicited_outbound_network','state'=>'FORBIDDEN','reason'=>'cell has no arbitrary remote-fetch authority'];
        if(!$this->hasPeerCapabilityEvidence($peers)) $unknowns[]=['subject'=>'peer_capability_roster','state'=>'UNKNOWN','reason'=>'no fresh signed peer capability evidence'];

        $world=[
            'schema'=>1,
            'observed_at'=>gmdate('c',$now),
            'expires_at'=>gmdate('c',$now+900),
            'trigger'=>$this->safeToken($trigger,64,'manual'),
            'world_id'=>'',
            'knowledge_state'=>$freshness,
            'self'=>[
                'cell_id'=>(string)($node['cell_id']??''),
                'state'=>(string)($node['state']??'unknown'),
                'generation'=>(int)($node['generation']??1),
                'root_id'=>(string)($node['root_id']??''),
                'parent_id'=>(string)($node['parent_id']??''),
                'location'=>[
                    'base_url'=>(string)($node['base_url']??''),
                    'scheme'=>(string)(parse_url((string)($node['base_url']??''),PHP_URL_SCHEME)?:''),
                    'host'=>(string)(parse_url((string)($node['base_url']??''),PHP_URL_HOST)?:''),
                    'logical_path'=>'/kicom',
                    'territory'=>'managed-cell-local',
                ],
            ],
            'environment'=>[
                'php_version'=>PHP_VERSION,
                'sapi'=>PHP_SAPI,
                'runtime_root'=>'managed-cell-runtime',
                'living_state'=>'cell-private-var/living',
                'direct_secret_visibility'=>'FORBIDDEN',
                'arbitrary_filesystem'=>'FORBIDDEN',
                'arbitrary_remote_fetch'=>'FORBIDDEN',
            ],
            'access'=>$access,
            'resources'=>is_array($perception['resources']??null)?$perception['resources']:[],
            'capabilities'=>$knownCaps,
            'neighbors'=>$peers,
            'actions'=>is_array($actions['actions']??null)?$actions['actions']:[],
            'boundaries'=>is_array($boundaries['boundaries']??null)?$boundaries['boundaries']:[],
            'expansion_opportunities'=>is_array($opportunities['opportunities']??null)?$opportunities['opportunities']:[],
            'last_action'=>$this->sanitizeAction($lastAction),
            'paused'=>[
                'autonomous_executable_evolution'=>'DEFERRED',
                'autonomous_reproduction'=>'DEFERRED',
            ],
            'unknowns'=>$unknowns,
            'evidence_policy'=>'Evidence can update knowledge; memory and goals never grant authority.',
        ];
        $world['knowledge_summary']=$this->knowledgeSummary($world);
        $world['world_id']=$this->worldHash($world);

        $before=$this->readJson($this->livingDir.'/perception/world.json');
        if(!$this->writeJson($this->livingDir.'/perception/world.json',$world)) return ['ok'=>false,'code'=>'CELL_WORLD_WRITE_FAILED'];
        if(!$this->appendJsonLine($this->livingDir.'/perception/world-history.jsonl',['ts'=>gmdate('c'),'type'=>'world_model','world'=>$world])) return ['ok'=>false,'code'=>'CELL_WORLD_HISTORY_FAILED'];
        if($before===null||($before['world_id']??'')!==$world['world_id']){
            if(!$this->appendJsonLine($this->livingDir.'/perception/world-changes.jsonl',[
                'ts'=>gmdate('c'),'type'=>'world_change','before_world_id'=>(string)($before['world_id']??'NONE'),
                'after_world_id'=>$world['world_id'],'trigger'=>$world['trigger'],
                'knowledge_summary'=>$world['knowledge_summary'],
            ])) return ['ok'=>false,'code'=>'CELL_WORLD_CHANGE_HISTORY_FAILED'];
        }

        $possibilities=$this->derivePossibilities($world);
        $oldPoss=$this->readJson($this->livingDir.'/action/possibilities.json');
        if(!$this->writeJson($this->livingDir.'/action/possibilities.json',$possibilities)) return ['ok'=>false,'code'=>'CELL_WORLD_POSSIBILITIES_WRITE_FAILED'];
        if($oldPoss===null||$this->stableHash($oldPoss)!==$this->stableHash($possibilities)){
            if(!$this->appendJsonLine($this->livingDir.'/action/possibility-history.jsonl',['ts'=>gmdate('c'),'type'=>'possibility_change','model'=>$possibilities])) return ['ok'=>false,'code'=>'CELL_WORLD_POSSIBILITY_HISTORY_FAILED'];
        }

        return ['ok'=>true,'code'=>'CELL_WORLD_REFRESHED','world'=>$world,'possibilities'=>$possibilities,'status'=>$this->status()];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $world=$this->readJson($this->livingDir.'/perception/world.json');
        $poss=$this->readJson($this->livingDir.'/action/possibilities.json');
        $ready=$world!==null&&$poss!==null
            &&is_file($this->livingDir.'/perception/world-history.jsonl')
            &&is_file($this->livingDir.'/perception/world-changes.jsonl')
            &&is_file($this->livingDir.'/action/possibility-history.jsonl')
            &&is_file($this->livingDir.'/perception/peer-memory.json');
        $state='UNKNOWN';
        if($world!==null){
            $exp=(int)(strtotime((string)($world['expires_at']??''))?:0);
            $state=$exp>0&&$exp<time()?'STALE':(string)($world['knowledge_state']??'UNKNOWN');
            if(!in_array($state,self::STATES,true))$state='UNKNOWN';
        }
        $peers=is_array($world['neighbors']??null)?$world['neighbors']:[];
        $summary=is_array($world['knowledge_summary']??null)?$world['knowledge_summary']:[];
        return [
            'ok'=>$ready,
            'code'=>$ready?'CELL_WORLD_READY':'CELL_WORLD_INCOMPLETE',
            'ready'=>$ready,
            'knowledge_state'=>$state,
            'world_id'=>(string)($world['world_id']??''),
            'observed_at'=>(string)($world['observed_at']??''),
            'neighbors'=>count($peers),
            'known'=>(int)($summary['known']??0),
            'unknown'=>(int)($summary['unknown']??0),
            'forbidden'=>(int)($summary['forbidden']??0),
            'stale'=>(int)($summary['stale']??0),
        ];
    }

    /**
     * Bounded report intended for a signed federation response. No secret bytes,
     * server filesystem paths or credentials are included.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $world=$this->readJson($this->livingDir.'/perception/world.json');
        $poss=$this->readJson($this->livingDir.'/action/possibilities.json');
        if($world===null||$poss===null) return ['ok'=>false,'code'=>'CELL_WORLD_REPORT_UNAVAILABLE'];
        return [
            'ok'=>true,
            'code'=>'CELL_WORLD_REPORT',
            'world_id'=>(string)($world['world_id']??''),
            'observed_at'=>(string)($world['observed_at']??''),
            'expires_at'=>(string)($world['expires_at']??''),
            'knowledge_state'=>(string)($this->status()['knowledge_state']??'UNKNOWN'),
            'self'=>$world['self']??[],
            'environment'=>$world['environment']??[],
            'access'=>$world['access']??[],
            'resources'=>$world['resources']??[],
            'capabilities'=>$world['capabilities']??[],
            'neighbors'=>$world['neighbors']??[],
            'actions'=>$world['actions']??[],
            'boundaries'=>$world['boundaries']??[],
            'expansion_opportunities'=>$world['expansion_opportunities']??[],
            'possibilities'=>$poss['possibilities']??[],
            'paused'=>$world['paused']??[],
            'unknowns'=>$world['unknowns']??[],
            'knowledge_summary'=>$world['knowledge_summary']??[],
        ];
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function ensureScaffold(array $node): array
    {
        if(!is_dir($this->livingDir.'/perception')||!is_dir($this->livingDir.'/action')) return ['ok'=>false,'code'=>'CELL_WORLD_PA_MISSING'];
        foreach(['perception/world-history.jsonl','perception/world-changes.jsonl','action/possibility-history.jsonl'] as $rel){
            $path=$this->livingDir.'/'.$rel;
            if(!is_file($path)&&!$this->atomicWrite($path,'',0600)) return ['ok'=>false,'code'=>'CELL_WORLD_HISTORY_CREATE_FAILED','path'=>$rel];
        }
        $peerPath=$this->livingDir.'/perception/peer-memory.json';
        if(!is_file($peerPath)){
            $peers=[];
            $pid=(string)($node['parent_id']??'');
            if($pid!=='')$peers[]=[
                'cell_id'=>$pid,'role'=>'parent','state'=>'UNKNOWN','base_url'=>(string)($node['parent_base_url']??''),
                'generation'=>max(0,(int)($node['generation']??1)-1),'capabilities'=>[],
                'last_seen'=>null,'evidence'=>'lineage-config','confidence'=>0.7,
            ];
            if(!$this->writeJson($peerPath,['schema'=>1,'updated_at'=>gmdate('c'),'peers'=>$peers])) return ['ok'=>false,'code'=>'CELL_WORLD_PEER_MEMORY_CREATE_FAILED'];
        }
        return ['ok'=>true,'code'=>'CELL_WORLD_SCAFFOLD_READY'];
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $ctx @return array<string,mixed> */
    private function rememberParentEvidence(array $node,array $ctx): array
    {
        $path=$this->livingDir.'/perception/peer-memory.json';
        $memory=$this->readJson($path)??['schema'=>1,'peers'=>[]];
        $rows=is_array($memory['peers']??null)?$memory['peers']:[];
        $indexed=[];
        foreach($rows as $row)if(is_array($row)&&preg_match('/^cell-[a-f0-9]{24}$/',(string)($row['cell_id']??'')))$indexed[(string)$row['cell_id']]=$row;

        $parentId=(string)($node['parent_id']??'');
        if($parentId!==''){
            $parent=is_array($ctx['parent']??null)?(array)$ctx['parent']:[];
            $parent['cell_id']=$parentId;
            $validated=$this->validatedPeer($parent,'parent','verified-signed-parent-message',1.0);
            if($validated!==null)$indexed[$parentId]=$validated;
            elseif(isset($indexed[$parentId])){
                $indexed[$parentId]['state']='AVAILABLE';$indexed[$parentId]['last_seen']=gmdate('c');
                $indexed[$parentId]['evidence']='verified-signed-parent-message';$indexed[$parentId]['confidence']=1.0;
            }
        }

        $self=(string)($node['cell_id']??'');
        foreach((is_array($ctx['peers']??null)?(array)$ctx['peers']:[]) as $peer){
            if(!is_array($peer))continue;
            $validated=$this->validatedPeer($peer,'federation-peer','signed-parent-peer-context',0.95);
            if($validated===null||$validated['cell_id']===$self||$validated['cell_id']===$parentId)continue;
            $indexed[$validated['cell_id']]=$validated;
        }

        $out=array_values($indexed);
        usort($out,static fn(array $a,array $b):int=>strcmp((string)$a['cell_id'],(string)$b['cell_id']));
        $memory=['schema'=>1,'updated_at'=>gmdate('c'),'peers'=>$out];
        if(!$this->writeJson($path,$memory)) return ['ok'=>false,'code'=>'CELL_WORLD_PEER_MEMORY_WRITE_FAILED'];
        return ['ok'=>true,'code'=>'CELL_WORLD_PEER_MEMORY_UPDATED','peers'=>$out];
    }

    private function agePeerMemory(): void
    {
        $path=$this->livingDir.'/perception/peer-memory.json';
        $memory=$this->readJson($path);if($memory===null)return;
        $rows=is_array($memory['peers']??null)?$memory['peers']:[];$changed=false;
        foreach($rows as &$row){
            if(!is_array($row))continue;$last=(int)(strtotime((string)($row['last_seen']??''))?:0);
            if($last>0&&time()-$last>1800&&($row['state']??'')==='AVAILABLE'){$row['state']='STALE';$changed=true;}
        }unset($row);
        if($changed)$this->writeJson($path,['schema'=>1,'updated_at'=>gmdate('c'),'peers'=>$rows]);
    }

    /** @param array<string,mixed> $peer @return array<string,mixed>|null */
    private function validatedPeer(array $peer,string $role,string $evidence,float $confidence): ?array
    {
        $id=(string)($peer['cell_id']??'');if(!preg_match('/^cell-[a-f0-9]{24}$/',$id))return null;
        $url=rtrim((string)($peer['base_url']??''),'/');
        if($url!==''){
            $p=parse_url($url);if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host']))$url='';
        }
        $caps=[];foreach((is_array($peer['capabilities']??null)?$peer['capabilities']:[]) as $cap){
            $cap=strtolower(trim((string)$cap));if($cap!==''&&preg_match('/^[a-z0-9_.-]{1,64}$/',$cap))$caps[$cap]=true;
        }
        return [
            'cell_id'=>$id,'role'=>$role,'state'=>'AVAILABLE','base_url'=>$url,
            'generation'=>max(0,(int)($peer['generation']??0)),'capabilities'=>array_keys($caps),
            'last_seen'=>gmdate('c'),'evidence'=>$evidence,'confidence'=>$confidence,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function accessMap(): array
    {
        $dirState=static function(string $path,bool $write):array{
            if(!is_dir($path))return ['read'=>'UNAVAILABLE','write'=>'UNAVAILABLE'];
            return ['read'=>'AVAILABLE','write'=>$write&&is_writable($path)?'AVAILABLE':'FORBIDDEN'];
        };
        $memory=$dirState($this->livingDir.'/memory',true);
        $workspace=$dirState($this->livingDir.'/workspace',true);
        $observer=$dirState($this->livingDir.'/observer',true);
        $candidates=$dirState($this->livingDir.'/evolution/candidates',true);
        return [
            'cell.runtime'=>['read'=>'AVAILABLE','direct_write'=>'FORBIDDEN','managed_upgrade'=>'AVAILABLE','evidence'=>'managed-runtime-policy'],
            'cell.canonical_memory'=>$memory+['execute'=>'FORBIDDEN','evidence'=>'local-filesystem-metadata'],
            'cell.workspace'=>$workspace+['execute'=>'FORBIDDEN','evidence'=>'local-filesystem-metadata'],
            'cell.observer'=>$observer+['execute'=>'FORBIDDEN','evidence'=>'local-filesystem-metadata'],
            'cell.genome_lkg'=>['read'=>is_dir($this->livingDir.'/genome/lkg')?'AVAILABLE':'UNAVAILABLE','direct_write'=>'FORBIDDEN','restore_via_immune'=>'AVAILABLE','evidence'=>'local-immune-policy'],
            'cell.evolution_candidates'=>$candidates+['execute'=>'FORBIDDEN','promotion'=>'DEFERRED','evidence'=>'local-evolution-policy'],
            'cell.secrets'=>['read'=>'FORBIDDEN','write'=>'FORBIDDEN','reason'=>'perception/action layer cannot expose credential material'],
            'federation.parent'=>['receive_signed'=>'AVAILABLE','reply_signed'=>'AVAILABLE','unsolicited_outbound'=>'FORBIDDEN','evidence'=>'federation-protocol'],
            'external.unprobed'=>['read'=>'UNKNOWN','write'=>'FORBIDDEN','reason'=>'unknown is not permission'],
        ];
    }

    /** @param array<string,mixed> $world @return array<string,mixed> */
    private function derivePossibilities(array $world): array
    {
        $neighborDelegation='UNKNOWN';
        foreach(($world['neighbors']??[]) as $peer){
            if(is_array($peer)&&($peer['state']??'')==='AVAILABLE'&&!empty($peer['capabilities'])){$neighborDelegation='AVAILABLE';break;}
        }
        return [
            'schema'=>1,'updated_at'=>gmdate('c'),'world_id'=>(string)($world['world_id']??''),
            'possibilities'=>[
                ['id'=>'refresh-world-model','state'=>'AVAILABLE','authority'=>'cell-internal','effect'=>'observe local/signed evidence and remember it'],
                ['id'=>'write-cell-memory','state'=>(string)($world['access']['cell.canonical_memory']['write']??'UNKNOWN'),'authority'=>'cell-internal','effect'=>'update local canonical cell memory'],
                ['id'=>'write-workspace','state'=>(string)($world['access']['cell.workspace']['write']??'UNKNOWN'),'authority'=>'cell-internal','effect'=>'develop/test cell-local artifacts'],
                ['id'=>'create-capability-candidate','state'=>(string)($world['access']['cell.evolution_candidates']['write']??'UNKNOWN'),'authority'=>'cell-internal-candidate','effect'=>'stage inert candidate; deterministic fitness allowed','promotion'=>'DEFERRED'],
                ['id'=>'delegate-to-neighbor','state'=>$neighborDelegation,'authority'=>'signed-federation','effect'=>'delegate only after peer capability evidence'],
                ['id'=>'request-protected-external-access','state'=>'AVAILABLE','authority'=>'request-only','effect'=>'bundle a human authorization request when an external system truly requires it'],
                ['id'=>'perform-protected-external-write','state'=>'FORBIDDEN','authority'=>'external-system-dependent','effect'=>'blocked until required external authorization exists'],
                ['id'=>'autonomous-reproduction','state'=>'DEFERRED','authority'=>'paused-policy','effect'=>'none'],
                ['id'=>'autonomous-executable-evolution','state'=>'DEFERRED','authority'=>'paused-policy','effect'=>'none'],
            ],
            'rule'=>'A possible request is not authority to perform the protected external action.',
        ];
    }

    /** @param array<string,mixed> $world @return array<string,int> */
    private function knowledgeSummary(array $world): array
    {
        $c=['known'=>0,'unknown'=>0,'forbidden'=>0,'stale'=>0,'degraded'=>0,'unavailable'=>0];
        $walk=function($v)use(&$walk,&$c):void{
            if(is_array($v)){foreach($v as $k=>$x){
                if(($k==='state'||$k==='read'||$k==='write'||$k==='direct_write'||$k==='receive_signed'||$k==='reply_signed'||$k==='unsolicited_outbound'||$k==='promotion')&&is_string($x)){
                    $u=strtoupper($x);
                    if($u==='AVAILABLE')$c['known']++;elseif($u==='UNKNOWN')$c['unknown']++;elseif($u==='FORBIDDEN')$c['forbidden']++;elseif($u==='STALE')$c['stale']++;elseif($u==='DEGRADED')$c['degraded']++;elseif($u==='UNAVAILABLE')$c['unavailable']++;
                }
                $walk($x);
            }}
        };
        $walk($world['access']??[]);$walk($world['capabilities']??[]);$walk($world['neighbors']??[]);$walk($world['boundaries']??[]);
        return $c;
    }

    /** @param list<array<string,mixed>> $peers */
    private function hasPeerCapabilityEvidence(array $peers): bool
    {
        foreach($peers as $p)if(is_array($p)&&($p['state']??'')==='AVAILABLE'&&!empty($p['capabilities']))return true;
        return false;
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function sanitizeAction(?array $row): ?array
    {
        if($row===null)return null;
        return [
            'ts'=>(string)($row['ts']??''),'action'=>(string)($row['action']??''),
            'target'=>(string)($row['target']??''),'result'=>(string)($row['result']??''),
        ];
    }

    /** @param array<string,mixed> $world */
    private function worldHash(array $world): string
    {
        unset($world['observed_at'],$world['expires_at'],$world['world_id'],$world['trigger']);
        return $this->stableHash($world);
    }

    /** @param array<string,mixed> $row */
    private function stableHash(array $row): string
    {
        unset($row['updated_at'],$row['observed_at'],$row['expires_at'],$row['trigger']);
        $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return hash('sha256',is_string($json)?$json:'');
    }

    /** @return array<string,mixed>|null */
    private function lastJsonLine(string $path): ?array
    {
        if(!is_file($path))return null;$fh=@fopen($path,'rb');if($fh===false)return null;
        $last='';while(($line=fgets($fh))!==false){$line=trim($line);if($line!=='')$last=$line;}fclose($fh);
        if($last==='')return null;$j=json_decode($last,true);return is_array($j)?$j:null;
    }

    private function safeToken(string $value,int $max,string $fallback): string
    {
        $value=trim($value);if($value===''||strlen($value)>$max||!preg_match('/^[A-Za-z0-9_.:-]+$/',$value))return $fallback;return $value;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if(!is_file($path)||is_link($path))return null;$raw=@file_get_contents($path);if(!is_string($raw)||strlen($raw)>1048576)return null;$j=json_decode($raw,true);return is_array($j)?$j:null;
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
