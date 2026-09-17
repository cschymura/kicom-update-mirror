<?php
declare(strict_types=1);

/**
 * Intrinsic living substrate for a daughter KiCom cell.
 *
 * The whole intrinsic tree is built below a private staging directory and is
 * committed by one directory rename. A child therefore never becomes a valid
 * node with only part of its memory/workspace/observer/genome/immune/evolution
 * substrate present.
 */
final class KiComExpansionCellLiving
{
    private string $storageDir;
    private string $rootDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir=rtrim($storageDir,'/');
        $this->rootDir=dirname($this->storageDir);
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir,0700,true) && !is_dir($this->storageDir)) {
            throw new RuntimeException('CELL_LIVING_STORAGE_UNAVAILABLE');
        }
        @chmod($this->storageDir,0700);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function initialize(array $context): array
    {
        foreach (['cell_id','base_url','parent_id'] as $key) {
            if (!isset($context[$key])||!is_string($context[$key])||trim($context[$key])==='') {
                return ['ok'=>false,'code'=>'CELL_LIVING_CONTEXT_INVALID'];
            }
        }
        if (!preg_match('/^cell-[a-f0-9]{24}$/',(string)$context['cell_id'])) return ['ok'=>false,'code'=>'CELL_LIVING_CELL_ID_INVALID'];
        if (is_dir($this->livingDir())) return ['ok'=>false,'code'=>'CELL_LIVING_ALREADY_INITIALIZED'];

        $schema=$this->loadSchema();
        if (empty($schema['ok'])) return $schema;
        $definition=(array)$schema['schema'];
        $components=$this->runtimeComponents($definition);
        if (empty($components['ok'])) return $components;

        $stage=$this->storageDir.'/.living-birth-'.bin2hex(random_bytes(6));
        if (!@mkdir($stage,0700,true) && !is_dir($stage)) return ['ok'=>false,'code'=>'CELL_LIVING_STAGE_CREATE_FAILED'];
        @chmod($stage,0700);

        try {
            foreach (['memory','workspace','observer','genome/lkg','immune','evolution/candidates','evolution/history','quarantine'] as $rel) {
                if (!@mkdir($stage.'/'.$rel,0700,true) && !is_dir($stage.'/'.$rel)) return ['ok'=>false,'code'=>'CELL_LIVING_SUBSYSTEM_CREATE_FAILED','subsystem'=>$rel];
                @chmod($stage.'/'.$rel,0700);
            }

            $memory=$this->memorySeed((string)$context['cell_id'],(string)$context['base_url'],(string)$context['parent_id']);
            foreach ($memory as $name=>$content) {
                if (!$this->atomicWrite($stage.'/memory/'.$name,$content,0600)) return ['ok'=>false,'code'=>'CELL_LIVING_MEMORY_WRITE_FAILED','resource'=>$name];
            }
            if (!$this->atomicWrite($stage.'/workspace/README.txt',"KiCom daughter-cell local workspace.\nNon-web-executable, cell-local and independently versioned.\n",0600)) return ['ok'=>false,'code'=>'CELL_LIVING_WORKSPACE_WRITE_FAILED'];

            $event=['ts'=>gmdate('c'),'type'=>'cell_intrinsic_birth','severity'=>'info','data'=>['cell_id'=>(string)$context['cell_id'],'subsystems'=>(array)$definition['required_subsystems']]];
            if (!$this->appendJsonLine($stage.'/observer/events.jsonl',$event)) return ['ok'=>false,'code'=>'CELL_LIVING_OBSERVER_WRITE_FAILED'];
            if (!$this->writeJson($stage.'/observer/state.json',['schema'=>1,'created_at'=>gmdate('c'),'events'=>1,'last_event'=>'cell_intrinsic_birth'])) return ['ok'=>false,'code'=>'CELL_LIVING_OBSERVER_STATE_FAILED'];

            $genomeComponents=[];
            foreach ((array)$components['components'] as $component) {
                $rel=(string)$component['path'];
                $src=$this->rootDir.'/'.$rel;
                $raw=@file_get_contents($src);
                if (!is_string($raw)) return ['ok'=>false,'code'=>'CELL_LIVING_RUNTIME_READ_FAILED','path'=>$rel];
                $dst=$stage.'/genome/lkg/'.$rel;
                if (!$this->atomicWrite($dst,$raw,0600)) return ['ok'=>false,'code'=>'CELL_LIVING_LKG_WRITE_FAILED','path'=>$rel];
                $genomeComponents[]=['path'=>$rel,'sha256'=>hash('sha256',$raw),'auto_heal'=>true,'role'=>(string)($component['role']??'runtime')];
            }
            $genome=[
                'schema'=>1,
                'id'=>'living-'.substr(hash('sha256',(string)$context['cell_id'].'|'.(string)$schema['sha256']),0,24),
                'cell_id'=>(string)$context['cell_id'],
                'generation'=>1,
                'created_at'=>gmdate('c'),
                'schema_sha256'=>(string)$schema['sha256'],
                'components'=>$genomeComponents,
                'invariants'=>['local-identity','local-memory','local-workspace','local-lkg','detect-and-heal-drift','no-secret-inheritance','internal-evolution-static-fitness','protected-external-boundaries-separate'],
            ];
            if (!$this->writeJson($stage.'/genome/genome.json',$genome)) return ['ok'=>false,'code'=>'CELL_LIVING_GENOME_WRITE_FAILED'];

            $immune=[
                'schema'=>1,
                'mode'=>'detect-and-restore-from-local-lkg',
                'managed_components'=>count($genomeComponents),
                'created_at'=>gmdate('c'),
                'quarantine'=>'living/quarantine',
                'last_scan'=>null,
            ];
            if (!$this->writeJson($stage.'/immune/policy.json',$immune)) return ['ok'=>false,'code'=>'CELL_LIVING_IMMUNE_WRITE_FAILED'];

            $evolution=[
                'schema'=>1,
                'candidate_area'=>'living/evolution/candidates',
                'fitness'=>'static-deterministic',
                'promotion_policy'=>'autonomous-internal-after-static-fitness-with-local-lkg-rollback',
                'external_boundary_policy'=>'human-authorization-only-when-protected-external-system-or-credential-requires-it',
                'created_at'=>gmdate('c'),
            ];
            if (!$this->writeJson($stage.'/evolution/policy.json',$evolution)) return ['ok'=>false,'code'=>'CELL_LIVING_EVOLUTION_WRITE_FAILED'];
            if (!$this->atomicWrite($stage.'/evolution/candidates/README.txt',"Candidate packages are inert until deterministic fitness passes.\nNo inherited parent secrets or mutable parent memory are permitted.\n",0600)) return ['ok'=>false,'code'=>'CELL_LIVING_EVOLUTION_CANDIDATE_AREA_FAILED'];

            $description=[
                'schema'=>1,
                'cell_id'=>(string)$context['cell_id'],
                'base_url'=>rtrim((string)$context['base_url'],'/'),
                'parent_id'=>(string)$context['parent_id'],
                'state'=>'enrolling',
                'born_at'=>gmdate('c'),
                'intrinsic_schema_sha256'=>(string)$schema['sha256'],
                'subsystems'=>(array)$definition['required_subsystems'],
                'runtime_components'=>array_column($genomeComponents,'path'),
                'capabilities'=>$this->normalizeCapabilities($context['capabilities']??[]),
            ];
            if (!$this->writeJson($stage.'/self-description.json',$description)) return ['ok'=>false,'code'=>'CELL_LIVING_DESCRIPTION_WRITE_FAILED'];

            $check=$this->validateTree($stage,$definition);
            if (empty($check['ok'])) return $check;
            if (!@rename($stage,$this->livingDir())) return ['ok'=>false,'code'=>'CELL_LIVING_COMMIT_FAILED'];
            @chmod($this->livingDir(),0700);
            return ['ok'=>true,'code'=>'CELL_LIVING_BORN','intrinsic_ready'=>true,'subsystems'=>$check['subsystems'],'genome_id'=>$genome['id']];
        } finally {
            if (is_dir($stage)) $this->rmTree($stage);
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $sub=[
            'identity'=>$this->identityReady(),
            'canonical_memory'=>$this->memoryReady(),
            'workspace'=>is_dir($this->livingDir().'/workspace'),
            'observer'=>is_file($this->livingDir().'/observer/events.jsonl')&&is_file($this->livingDir().'/observer/state.json'),
            'genome_lkg'=>false,
            'immune'=>is_file($this->livingDir().'/immune/policy.json'),
            'evolution'=>is_file($this->livingDir().'/evolution/policy.json')&&is_dir($this->livingDir().'/evolution/candidates'),
        ];
        $scan=$this->scan();
        $sub['genome_lkg']=!empty($scan['ok'])&&!empty($scan['lkg_ok']);
        $ready=!in_array(false,$sub,true)&&empty($scan['drift']);
        return [
            'ok'=>is_dir($this->livingDir()),
            'code'=>$ready?'CELL_LIVING_READY':'CELL_LIVING_INCOMPLETE',
            'living_ready'=>$ready,
            'subsystems'=>$sub,
            'drift_count'=>count($scan['drift']??[]),
            'lkg_ok'=>(bool)($scan['lkg_ok']??false),
            'genome_id'=>(string)($scan['genome_id']??''),
        ];
    }

    /** @return array<string,mixed> */
    public function doctor(bool $heal=false): array
    {
        $before=$this->scan();
        $healResult=null;
        if ($heal&&!empty($before['ok'])&&!empty($before['drift'])) $healResult=$this->heal();
        $after=$healResult!==null?$this->scan():$before;
        $status=$this->status();
        return ['ok'=>!empty($status['living_ready']),'code'=>!empty($status['living_ready'])?'CELL_DOCTOR_HEALTHY':'CELL_DOCTOR_DEGRADED','status'=>$status,'scan'=>$after,'heal'=>$healResult];
    }

    /** @return array<string,mixed> */
    public function scan(): array
    {
        $g=$this->readJson($this->livingDir().'/genome/genome.json');
        if ($g===null||!is_array($g['components']??null)) return ['ok'=>false,'code'=>'CELL_LIVING_GENOME_UNAVAILABLE','drift'=>[],'lkg_ok'=>false];
        $drift=[];$lkgOk=true;
        foreach ($g['components'] as $component) {
            if (!is_array($component)) return ['ok'=>false,'code'=>'CELL_LIVING_GENOME_INVALID','drift'=>$drift,'lkg_ok'=>false];
            $rel=$this->safeRel((string)($component['path']??''));$expected=strtolower((string)($component['sha256']??''));
            if ($rel===null||!preg_match('/^[a-f0-9]{64}$/',$expected)) return ['ok'=>false,'code'=>'CELL_LIVING_GENOME_INVALID','drift'=>$drift,'lkg_ok'=>false];
            $live=$this->rootDir.'/'.$rel;$actual=is_file($live)?(hash_file('sha256',$live)?:''):'MISSING';
            if (!hash_equals($expected,$actual)) $drift[]=['path'=>$rel,'expected'=>$expected,'actual'=>$actual,'auto_heal'=>!empty($component['auto_heal'])];
            $lkg=$this->livingDir().'/genome/lkg/'.$rel;
            if (!is_file($lkg)||!hash_equals($expected,hash_file('sha256',$lkg)?:'')) $lkgOk=false;
        }
        return ['ok'=>true,'code'=>empty($drift)?'CELL_LIVING_SCAN_CLEAN':'CELL_LIVING_DRIFT','genome_id'=>(string)($g['id']??''),'drift'=>$drift,'lkg_ok'=>$lkgOk,'components'=>count($g['components'])];
    }

    /** @return array<string,mixed> */
    public function heal(): array
    {
        $scan=$this->scan();
        if (empty($scan['ok'])||empty($scan['lkg_ok'])) return ['ok'=>false,'code'=>'CELL_LIVING_LKG_NOT_READY'];
        $repaired=[];
        foreach ($scan['drift'] as $d) {
            if (empty($d['auto_heal'])) continue;
            $rel=$this->safeRel((string)$d['path']); if ($rel===null) return ['ok'=>false,'code'=>'CELL_LIVING_HEAL_PATH_INVALID'];
            $src=$this->livingDir().'/genome/lkg/'.$rel;$raw=@file_get_contents($src);
            if (!is_string($raw)||!hash_equals((string)$d['expected'],hash('sha256',$raw))) return ['ok'=>false,'code'=>'CELL_LIVING_LKG_CORRUPT','path'=>$rel];
            if (!$this->atomicWrite($this->rootDir.'/'.$rel,$raw,0644)) return ['ok'=>false,'code'=>'CELL_LIVING_HEAL_WRITE_FAILED','path'=>$rel];
            $repaired[]=$rel;
        }
        $this->recordEvent('immune_heal',empty($repaired)?'info':'warn',['repaired'=>$repaired]);
        $after=$this->scan();
        return ['ok'=>!empty($after['ok'])&&empty($after['drift']),'code'=>empty($after['drift'])?'CELL_LIVING_HEALED':'CELL_LIVING_HEAL_INCOMPLETE','repaired'=>$repaired,'scan'=>$after];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public function evaluateCandidate(array $candidate): array
    {
        $g=$this->readJson($this->livingDir().'/genome/genome.json');
        if ($g===null||!is_array($g['components']??null)) return ['ok'=>false,'code'=>'CELL_LIVING_GENOME_UNAVAILABLE'];
        $managed=[];foreach($g['components'] as $c)if(is_array($c)&&isset($c['path']))$managed[(string)$c['path']]=true;
        $changes=$candidate['changes']??null;
        if (!is_array($changes)||count($changes)<1||count($changes)>16) return ['ok'=>false,'code'=>'CELL_CANDIDATE_CHANGES_INVALID'];
        $checked=[];
        foreach ($changes as $i=>$change) {
            if (!is_array($change)) return ['ok'=>false,'code'=>'CELL_CANDIDATE_CHANGE_INVALID','entry'=>$i+1];
            $path=$this->safeRel((string)($change['path']??''));$sha=strtolower((string)($change['sha256']??''));$b64=(string)($change['content_b64']??'');
            if ($path===null||!isset($managed[$path])||!preg_match('/^[a-f0-9]{64}$/',$sha)) return ['ok'=>false,'code'=>'CELL_CANDIDATE_PATH_OR_HASH_INVALID','entry'=>$i+1];
            $raw=base64_decode($b64,true);if(!is_string($raw)||!hash_equals($sha,hash('sha256',$raw))) return ['ok'=>false,'code'=>'CELL_CANDIDATE_CONTENT_HASH_MISMATCH','entry'=>$i+1];
            if (str_ends_with(strtolower($path),'.php')) { try { token_get_all($raw,TOKEN_PARSE); } catch (ParseError $e) { return ['ok'=>false,'code'=>'CELL_CANDIDATE_PHP_SYNTAX_INVALID','entry'=>$i+1]; } }
            $checked[]=['path'=>$path,'sha256'=>$sha,'bytes'=>strlen($raw)];
        }
        return ['ok'=>true,'code'=>'CELL_CANDIDATE_FITNESS_PASS','fitness'=>'pass','changes'=>$checked,'eligible_for_internal_promotion'=>true,'requires_external_authorization'=>false];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public function stageCandidate(array $candidate): array
    {
        $fit=$this->evaluateCandidate($candidate); if (empty($fit['ok'])) return $fit;
        $id=substr(hash('sha256',json_encode($candidate,JSON_UNESCAPED_SLASHES)?:''),0,24);
        $row=['schema'=>1,'id'=>$id,'created_at'=>gmdate('c'),'status'=>'fitness_passed','fitness'=>$fit,'candidate'=>$candidate];
        if (!$this->writeJson($this->livingDir().'/evolution/candidates/'.$id.'.json',$row)) return ['ok'=>false,'code'=>'CELL_CANDIDATE_STAGE_FAILED'];
        $this->recordEvent('evolution_candidate_staged','info',['candidate_id'=>$id,'changes'=>count($fit['changes'])]);
        return ['ok'=>true,'code'=>'CELL_CANDIDATE_STAGED','candidate_id'=>$id,'fitness'=>$fit];
    }

    /** @param array<string,mixed> $node */
    public function recordActivation(array $node): bool
    {
        $desc=$this->readJson($this->livingDir().'/self-description.json'); if($desc===null)return false;
        $desc['state']='active';$desc['root_id']=(string)($node['root_id']??'');$desc['parent_id']=(string)($node['parent_id']??'');$desc['generation']=(int)($node['generation']??1);$desc['activated_at']=gmdate('c');
        if(!$this->writeJson($this->livingDir().'/self-description.json',$desc))return false;
        $project=$this->projectStateKcl((string)($node['cell_id']??''),(string)($node['base_url']??''),(string)($node['parent_id']??''),'active',(string)($node['root_id']??''),(int)($node['generation']??1));
        if(!$this->atomicWrite($this->livingDir().'/memory/PROJECT_STATE.kcl',$project,0600))return false;
        return $this->recordEvent('cell_activated','info',['root_id'=>$desc['root_id'],'parent_id'=>$desc['parent_id'],'generation'=>$desc['generation']]);
    }

    public function destroyIfUncommitted(): void
    {
        if (!is_file($this->storageDir.'/node.json') && is_dir($this->livingDir())) $this->rmTree($this->livingDir());
    }

    /** @return array<string,mixed> */
    private function loadSchema(): array
    {
        $path=$this->rootDir.'/living-schema.json';$raw=@file_get_contents($path);
        if (!is_string($raw)) return ['ok'=>false,'code'=>'CELL_LIVING_SCHEMA_MISSING'];
        $j=json_decode($raw,true); if(!is_array($j)||(int)($j['schema']??0)!==1) return ['ok'=>false,'code'=>'CELL_LIVING_SCHEMA_INVALID'];
        $required=['identity','canonical_memory','workspace','observer','genome_lkg','immune','evolution'];$got=array_values(array_filter($j['required_subsystems']??[],'is_string'));
        foreach($required as $r)if(!in_array($r,$got,true))return ['ok'=>false,'code'=>'CELL_LIVING_SCHEMA_SUBSYSTEM_MISSING','subsystem'=>$r];
        return ['ok'=>true,'schema'=>$j,'sha256'=>hash('sha256',$raw)];
    }

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    private function runtimeComponents(array $schema): array
    {
        $rows=$schema['runtime_components']??null;if(!is_array($rows)||!$rows)return ['ok'=>false,'code'=>'CELL_LIVING_RUNTIME_SCHEMA_INVALID'];$out=[];
        foreach($rows as $row){if(!is_array($row))return ['ok'=>false,'code'=>'CELL_LIVING_RUNTIME_SCHEMA_INVALID'];$p=$this->safeRel((string)($row['path']??''));if($p===null||!is_file($this->rootDir.'/'.$p))return ['ok'=>false,'code'=>'CELL_LIVING_RUNTIME_COMPONENT_MISSING','path'=>(string)($row['path']??'')];$out[]=['path'=>$p,'role'=>(string)($row['role']??'runtime')];}
        return ['ok'=>true,'components'=>$out];
    }

    /** @return array<string,string> */
    private function memorySeed(string $cellId,string $baseUrl,string $parentId): array
    {
        $state=$this->projectStateKcl($cellId,$baseUrl,$parentId,'enrolling','',1);
        return [
            'PROJECT_STATE.kcl'=>$state,
            'ARCHITECTURE.kcl'=>"ARCHITECTURE cell\nCOMPONENT identity role=\"local\"\nCOMPONENT memory role=\"canonical-local\"\nCOMPONENT workspace role=\"isolated-local\"\nCOMPONENT observer role=\"append-only-trace\"\nCOMPONENT genome_lkg role=\"desired-state-and-local-recovery\"\nCOMPONENT immune role=\"drift-detect-and-heal\"\nCOMPONENT evolution role=\"candidate-static-fitness-autonomous-internal-promotion\"\nRULE \"No parent secret, credential or mutable project memory is inherited.\"\nEND_ARCHITECTURE cell\n",
            'PROTOCOL.kcl'=>"PROTOCOL KCL-CELL/1\nRULE \"Federation messages are signed and peer trust is local.\"\nRULE \"Enrollment establishes lineage/trust only; it does not add intrinsic capabilities.\"\nEND_PROTOCOL KCL-CELL/1\n",
            'DECISIONS.kcl'=>"DECISIONS cell\nDECISION C001 status=accepted title=\"Complete at birth\"\nRATIONALE C001 \"Identity, memory, workspace, observer, genome/LKG, immune and evolution substrate are intrinsic.\"\nDECISION C002 status=accepted title=\"Local authority\"\nRATIONALE C002 \"Internal evolution is autonomous after deterministic fitness; protected external boundaries remain separate.\"\nEND_DECISIONS cell\n",
            'CHANGELOG.kcl'=>"CHANGELOG cell\nEVENT \"".gmdate('c')."\" change=\"Intrinsic living substrate born locally\"\nEND_CHANGELOG cell\n",
            'NEXT.kcl'=>"NEXT cell\nPRIORITY 1 goal=\"Establish signed lineage, then operate from local memory/genome without receiving basic capabilities from the parent\"\nCONSTRAINT \"No hard delete of retained project experience\"\nEND_NEXT cell\n",
        ];
    }

    private function projectStateKcl(string $cellId,string $baseUrl,string $parentId,string $state,string $rootId,int $generation): string
    {
        return "PROJECT cell\nSTATE \"".$state."\"\nFACT cell_id=\"".$cellId."\"\nFACT base_url=\"".rtrim($baseUrl,'/')."\"\nFACT parent_id=\"".$parentId."\"\nFACT root_id=\"".$rootId."\"\nFACT generation=".max(1,$generation)."\nFACT intrinsic_complete=true\nRULE \"Local identity, mutable memory and secrets are not cloned from the parent.\"\nEND_PROJECT cell\n";
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function validateTree(string $root,array $definition): array
    {
        $sub=[
            'canonical_memory'=>$this->memoryReadyAt($root),
            'workspace'=>is_dir($root.'/workspace'),
            'observer'=>is_file($root.'/observer/events.jsonl')&&is_file($root.'/observer/state.json'),
            'genome_lkg'=>$this->genomeReadyAt($root),
            'immune'=>is_file($root.'/immune/policy.json'),
            'evolution'=>is_file($root.'/evolution/policy.json')&&is_dir($root.'/evolution/candidates'),
        ];
        if(in_array(false,$sub,true))return ['ok'=>false,'code'=>'CELL_LIVING_TREE_INCOMPLETE','subsystems'=>$sub];
        return ['ok'=>true,'code'=>'CELL_LIVING_TREE_COMPLETE','subsystems'=>$sub];
    }

    private function identityReady(): bool
    {
        $node=$this->readJson($this->storageDir.'/node.json');$secret=@file_get_contents($this->storageDir.'/signing.secret');
        return $node!==null&&preg_match('/^cell-[a-f0-9]{24}$/',(string)($node['cell_id']??''))===1&&is_string($secret)&&strlen($secret)===SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
    }
    private function memoryReady(): bool { return $this->memoryReadyAt($this->livingDir()); }
    private function memoryReadyAt(string $root): bool { foreach(['PROJECT_STATE.kcl','ARCHITECTURE.kcl','PROTOCOL.kcl','DECISIONS.kcl','CHANGELOG.kcl','NEXT.kcl'] as $f)if(!is_file($root.'/memory/'.$f))return false;return true; }
    private function genomeReadyAt(string $root): bool
    {
        $g=$this->readJson($root.'/genome/genome.json');if($g===null||!is_array($g['components']??null)||!$g['components'])return false;
        foreach($g['components'] as $c){if(!is_array($c))return false;$p=$this->safeRel((string)($c['path']??''));$sha=(string)($c['sha256']??'');if($p===null||!is_file($root.'/genome/lkg/'.$p)||!hash_equals($sha,hash_file('sha256',$root.'/genome/lkg/'.$p)?:''))return false;}
        return true;
    }
    private function livingDir(): string { return $this->storageDir.'/living'; }
    private function safeRel(string $path): ?string { $path=ltrim(str_replace('\\','/',trim($path)),'/');if($path===''||strlen($path)>220||str_contains($path,"\0")||str_contains($path,'..')||!preg_match('~^[A-Za-z0-9_./-]+$~',$path))return null;foreach(explode('/',$path) as $s)if($s===''||$s==='.'||$s==='..')return null;return $path; }
    /** @param mixed $caps @return list<string> */
    private function normalizeCapabilities($caps): array { if(!is_array($caps))return[];$out=[];foreach($caps as $cap){$c=strtolower(trim((string)$cap));if($c!==''&&preg_match('/^[a-z0-9_.-]{1,64}$/',$c))$out[$c]=true;}return array_keys($out); }
    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array { if(!is_file($path))return null;$raw=@file_get_contents($path);if(!is_string($raw))return null;$j=json_decode($raw,true);return is_array($j)?$j:null; }
    /** @param array<string,mixed> $row */
    private function writeJson(string $path,array $row): bool { $j=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return is_string($j)&&$this->atomicWrite($path,$j."\n",0600); }
    /** @param array<string,mixed> $row */
    private function appendJsonLine(string $path,array $row): bool { $j=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($j))return false;$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return false;$ok=@file_put_contents($path,$j."\n",FILE_APPEND|LOCK_EX)!==false;if($ok)@chmod($path,0600);return $ok; }
    /** @param array<string,mixed> $data */
    private function recordEvent(string $type,string $severity,array $data): bool { return $this->appendJsonLine($this->livingDir().'/observer/events.jsonl',['ts'=>gmdate('c'),'type'=>$type,'severity'=>$severity,'data'=>$data]); }
    private function atomicWrite(string $path,string $content,int $mode): bool { $dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return false;$tmp=$path.'.tmp.'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$content,LOCK_EX)===false)return false;@chmod($tmp,$mode);if(!@rename($tmp,$path)){@unlink($tmp);return false;}@chmod($path,$mode);return true; }
    private function rmTree(string $dir): void { if(!is_dir($dir))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($dir); }
}
