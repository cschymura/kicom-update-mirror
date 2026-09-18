<?php
declare(strict_types=1);

/**
 * Evidence-only evaluator for daughter-cell autonomous evolution promotion.
 * It cannot promote, mutate runtime/LKG/genome, grant authority, or probe remotes.
 */
final class KiComExpansionCellEvolutionReadiness
{
    private string $livingDir;
    public function __construct(string $storageDir){$this->livingDir=rtrim($storageDir,'/').'/living';}

    /** @return array<string,mixed> */
    public function evaluate(): array
    {
        $reasons=[];
        $current=$this->json('perception/current.json');
        $model=$this->json('action/model.json');
        $boundaries=$this->json('action/boundaries.json');
        $policy=$this->json('evolution/policy.json');
        $ph=$this->jsonl('perception/history.jsonl');
        $ah=$this->jsonl('action/history.jsonl');
        if($current===null||$model===null||$boundaries===null||$policy===null){$reasons[]='required-materialized-evidence-missing';}
        $cleanSnapshots=0;
        foreach($ph as $row){$s=$row['snapshot']??null;if(is_array($s)&&($s['overall_state']??'')==='AVAILABLE')$cleanSnapshots++;}
        $successfulCycles=0;$unsafeResults=0;
        foreach($ah as $row){
            $r=(string)($row['result']??'unknown');
            if($r==='success'&&in_array((string)($row['action']??''),['perception.refresh','federation.tick.receive'],true))$successfulCycles++;
            if(in_array($r,['failed','degraded'],true))$unsafeResults++;
        }
        if($cleanSnapshots<3)$reasons[]='insufficient-clean-perception-history';
        if($successfulCycles<2)$reasons[]='insufficient-successful-action-cycles';
        if($unsafeResults>0)$reasons[]='recent-action-failure-or-degradation-present';
        if(($current['overall_state']??'UNKNOWN')!=='AVAILABLE')$reasons[]='current-perception-not-available';
        if(!is_array($model['actions']??null)||count($model['actions'])<1)$reasons[]='action-model-empty';
        if(!is_array($boundaries['boundaries']??null)||count($boundaries['boundaries'])<1)$reasons[]='boundary-model-empty';
        if(($policy['promotion_policy']??'')!=='deferred-until-perception-action-memory-is-implemented-and-verified')$reasons[]='promotion-policy-unexpected';
        return [
            'ok'=>true,
            'code'=>empty($reasons)?'CELL_EVOLUTION_EVIDENCE_READY':'CELL_EVOLUTION_EVIDENCE_NOT_READY',
            'evidence_ready'=>empty($reasons),
            'promotion_performed'=>false,
            'authority_changed'=>false,
            'thresholds'=>['clean_perception_snapshots'=>3,'successful_action_cycles'=>2,'allowed_failed_or_degraded_actions'=>0],
            'observed'=>['clean_perception_snapshots'=>$cleanSnapshots,'successful_action_cycles'=>$successfulCycles,'failed_or_degraded_actions'=>$unsafeResults],
            'reasons'=>$reasons,
            'note'=>'Readiness is advisory evidence only. Promotion remains a separate verifier/trust-governed operation and is not implemented here.'
        ];
    }

    /** @return array<string,mixed>|null */
    private function json(string $rel): ?array{$raw=@file_get_contents($this->livingDir.'/'.$rel);if(!is_string($raw))return null;$v=json_decode($raw,true);return is_array($v)?$v:null;}
    /** @return list<array<string,mixed>> */
    private function jsonl(string $rel): array{$raw=@file($this->livingDir.'/'.$rel,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if(!is_array($raw))return [];$out=[];foreach($raw as $line){$v=json_decode($line,true);if(is_array($v))$out[]=$v;}return $out;}
}
