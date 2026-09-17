<?php
declare(strict_types=1);

/**
 * KiCom Interaction Character v1
 *
 * Persists collaboration patterns across sessions without pretending that a
 * chat has a human personality. The model stores only interaction/workflow
 * preferences and command semantics. It must not become a sensitive user
 * profile, credential store or authority source.
 */
final class KiComInteractionCharacter
{
    public const SCHEMA = 1;
    private const LAYERS = ['core','adaptive','session'];
    private const DENIED_CATEGORIES = [
        'health','medical','race','ethnicity','religion','politics','political',
        'sexual','sex_life','criminal','biometric','password','secret','credential',
        'token','private_key','otp','exact_address','financial_account'
    ];

    private string $root;
    private string $stateFile;
    private string $historyFile;

    public function __construct(string $root)
    {
        $root=rtrim($root,'/');
        if($root==='') throw new InvalidArgumentException('CHARACTER_ROOT_REQUIRED');
        $this->root=$root;
        $this->stateFile=$root.'/character.json';
        $this->historyFile=$root.'/history.jsonl';
        $this->ensureStorage();
    }

    public function status(): array
    {
        $s=$this->load();
        return [
            'ok'=>true,
            'schema'=>self::SCHEMA,
            'policy'=>'interaction-continuity-not-sensitive-profiling; archive-never-hard-delete',
            'generation'=>(int)$s['generation'],
            'core'=>count($s['layers']['core']),
            'adaptive'=>count($s['layers']['adaptive']),
            'session'=>count($s['layers']['session']),
            'history_entries'=>$this->historyCount(),
        ];
    }

    /**
     * Observe an interaction pattern.
     * - session may be written immediately;
     * - adaptive becomes established after repeated consistent observations;
     * - core requires explicit human confirmation and is never auto-promoted.
     */
    public function observe(
        string $layer,
        string $key,
        string $value,
        string $source,
        float $confidence=0.5,
        bool $humanConfirmed=false
    ): array {
        $layer=strtolower(trim($layer));
        $key=$this->key($key);
        $value=$this->text($value,600);
        $source=$this->text($source,160);
        $confidence=max(0.0,min(1.0,$confidence));
        if(!in_array($layer,self::LAYERS,true)) return ['ok'=>false,'code'=>'CHARACTER_LAYER_INVALID'];
        if($key===''||$value===''||$source==='') return ['ok'=>false,'code'=>'CHARACTER_FIELDS_REQUIRED'];
        if($this->denied($key,$value)) return ['ok'=>false,'code'=>'CHARACTER_SENSITIVE_PROFILE_FORBIDDEN'];
        if($layer==='core'&&!$humanConfirmed) return ['ok'=>false,'code'=>'CHARACTER_CORE_REQUIRES_HUMAN_CONFIRMATION'];

        $s=$this->load();
        $now=gmdate('c');
        $existing=$s['layers'][$layer][$key]??null;
        $fingerprint=hash('sha256',$key."\n".mb_strtolower($value));
        $observations=1;
        if(is_array($existing)&&hash_equals((string)($existing['fingerprint']??''),$fingerprint)){
            $observations=(int)($existing['observations']??0)+1;
        }

        $state='candidate';
        if($layer==='session')$state='active';
        elseif($layer==='adaptive'&&$observations>=3)$state='active';
        elseif($layer==='core'&&$humanConfirmed)$state='active';

        $row=[
            'key'=>$key,
            'value'=>$value,
            'fingerprint'=>$fingerprint,
            'state'=>$state,
            'observations'=>$observations,
            'confidence'=>$confidence,
            'human_confirmed'=>$humanConfirmed,
            'first_seen_at'=>(string)($existing['first_seen_at']??$now),
            'last_seen_at'=>$now,
            'source_class'=>$this->sourceClass($source),
        ];
        $s['layers'][$layer][$key]=$row;
        $s['generation']=(int)$s['generation']+1;
        $s['updated_at']=$now;
        $this->save($s);
        $this->append('pattern_observed',['layer'=>$layer,'key'=>$key,'state'=>$state,'observations'=>$observations,'source_class'=>$row['source_class']]);
        return ['ok'=>true,'code'=>'CHARACTER_PATTERN_OBSERVED','pattern'=>$row,'layer'=>$layer];
    }

    /** Explicitly replace a pattern while retaining old history. */
    public function supersede(string $layer,string $key,string $replacement,string $reason,bool $humanConfirmed=false): array
    {
        $layer=strtolower(trim($layer));$key=$this->key($key);$replacement=$this->text($replacement,600);$reason=$this->text($reason,300);
        if(!in_array($layer,self::LAYERS,true)||$key===''||$replacement===''||$reason==='')return ['ok'=>false,'code'=>'CHARACTER_SUPERSEDE_INVALID'];
        if($this->denied($key,$replacement))return ['ok'=>false,'code'=>'CHARACTER_SENSITIVE_PROFILE_FORBIDDEN'];
        if($layer==='core'&&!$humanConfirmed)return ['ok'=>false,'code'=>'CHARACTER_CORE_REQUIRES_HUMAN_CONFIRMATION'];
        $s=$this->load();$old=$s['layers'][$layer][$key]??null;if(!is_array($old))return ['ok'=>false,'code'=>'CHARACTER_PATTERN_NOT_FOUND'];
        $this->append('pattern_superseded',['layer'=>$layer,'key'=>$key,'old_fingerprint'=>(string)$old['fingerprint'],'reason'=>$reason]);
        unset($s['layers'][$layer][$key]);$s['updated_at']=gmdate('c');$s['generation']=(int)$s['generation']+1;$this->save($s);
        return $this->observe($layer,$key,$replacement,'supersede',1.0,$humanConfirmed);
    }

    /**
     * Portable bootstrap profile for a new chat/session. Session-only traits are
     * excluded by default so transient quirks do not become permanent identity.
     */
    public function bootstrapProfile(bool $includeSession=false): array
    {
        $s=$this->load();$out=['schema'=>self::SCHEMA,'generation'=>(int)$s['generation'],'core'=>[],'adaptive'=>[]];
        foreach(['core','adaptive'] as $layer){
            foreach($s['layers'][$layer] as $k=>$row){
                if(($row['state']??'')==='active')$out[$layer][$k]=(string)$row['value'];
            }
            ksort($out[$layer]);
        }
        if($includeSession){$out['session']=[];foreach($s['layers']['session'] as $k=>$row){if(($row['state']??'')==='active')$out['session'][$k]=(string)$row['value'];}ksort($out['session']);}
        $out['boundaries']=[
            'authority_source'=>'FORBIDDEN',
            'safety_override'=>'FORBIDDEN',
            'credential_storage'=>'FORBIDDEN',
            'sensitive_user_profiling'=>'FORBIDDEN',
            'history_hard_delete'=>'FORBIDDEN',
            'human_explicit_override'=>'HIGHEST_PRIORITY_WITHIN_ALLOWED_SCOPE',
        ];
        return $out;
    }

    public function beginNewSession(): array
    {
        $s=$this->load();
        foreach($s['layers']['session'] as $k=>$row){$this->append('session_pattern_archived',['key'=>$k,'fingerprint'=>(string)($row['fingerprint']??'')]);}
        $s['layers']['session']=[];$s['generation']=(int)$s['generation']+1;$s['updated_at']=gmdate('c');$this->save($s);
        return ['ok'=>true,'code'=>'CHARACTER_NEW_SESSION_READY','generation'=>$s['generation']];
    }

    private function denied(string $key,string $value): bool
    {
        $hay=mb_strtolower($key.' '.$value);
        foreach(self::DENIED_CATEGORIES as $term){if(str_contains($hay,$term))return true;}
        return false;
    }

    private function sourceClass(string $source): string
    {
        $s=mb_strtolower($source);
        if(str_contains($s,'human')||str_contains($s,'user'))return 'human';
        if(str_contains($s,'session')||str_contains($s,'chat'))return 'session-evidence';
        return 'system-evidence';
    }

    private function ensureStorage(): void
    {
        if(!is_dir($this->root)&&!@mkdir($this->root,0700,true)&&!is_dir($this->root))throw new RuntimeException('CHARACTER_STORAGE_CREATE_FAILED');
        if(!is_file($this->stateFile))$this->save(['schema'=>self::SCHEMA,'generation'=>1,'created_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'layers'=>['core'=>[],'adaptive'=>[],'session'=>[]]]);
        if(!is_file($this->historyFile)&&@file_put_contents($this->historyFile,'')===false)throw new RuntimeException('CHARACTER_HISTORY_CREATE_FAILED');
        @chmod($this->stateFile,0600);@chmod($this->historyFile,0600);
    }

    private function load(): array
    {
        $raw=@file_get_contents($this->stateFile);$s=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($s)||(int)($s['schema']??0)!==self::SCHEMA||!is_array($s['layers']??null))throw new RuntimeException('CHARACTER_STATE_INVALID');
        foreach(self::LAYERS as $l)if(!is_array($s['layers'][$l]??null))throw new RuntimeException('CHARACTER_LAYER_STATE_INVALID');
        return $s;
    }

    private function save(array $s): void
    {
        $json=json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($json))throw new RuntimeException('CHARACTER_STATE_ENCODE_FAILED');
        $tmp=$this->stateFile.'.tmp-'.substr(hash('sha256',uniqid('',true)),0,12);if(@file_put_contents($tmp,$json."\n",LOCK_EX)===false)throw new RuntimeException('CHARACTER_STATE_WRITE_FAILED');@chmod($tmp,0600);
        if(!@rename($tmp,$this->stateFile)){@unlink($tmp);throw new RuntimeException('CHARACTER_STATE_COMMIT_FAILED');}@chmod($this->stateFile,0600);
    }

    private function append(string $event,array $data): void
    {
        $row=['schema'=>self::SCHEMA,'at'=>gmdate('c'),'event'=>$event,'data'=>$data];$json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($json)||@file_put_contents($this->historyFile,$json."\n",FILE_APPEND|LOCK_EX)===false)throw new RuntimeException('CHARACTER_HISTORY_APPEND_FAILED');@chmod($this->historyFile,0600);
    }

    private function historyCount(): int{$h=@fopen($this->historyFile,'rb');if(!$h)return 0;$n=0;while(!feof($h)){if(fgets($h)!==false)$n++;}fclose($h);return $n;}
    private function key(string $v): string{$v=trim($v);if(strlen($v)>120)$v=substr($v,0,120);return preg_match('/^[A-Za-z0-9._:-]+$/',$v)?$v:'';}
    private function text(string $v,int $max): string{$v=trim(str_replace(["\0","\r"],['',''],$v));if(mb_strlen($v)>$max)$v=mb_substr($v,0,$max);return $v;}
}
