<?php
declare(strict_types=1);

/**
 * Read-only LAB admission diagnostic. It does not add child identity to
 * native R3, grant production privileges or trust an arbitrary KCL response.
 * Its only safe result is to keep the unbound child in quarantine.
 */
final class KiComChildBirthReadiness
{
    public static function inspect(string $nativeKcl, array $hostCandidate): array
    {
        $status = [
            'code'=>'NATIVE_RUNTIME_UNVERIFIED',
            'daughter_born'=>false,
            'native_genome_bound'=>false,
            'recovery_qualified'=>false,
            'egress_qualified'=>false,
            'communication_parity_qualified'=>false,
            'deployment_authorized'=>false,
            'external_action_authorized'=>false,
        ];
        if (!str_starts_with($nativeKcl,"KCL/1\n")
            || !preg_match('/^FACT genome_id="([A-Za-z0-9._-]{3,80})"$/mD',$nativeKcl,$match)) {
            return $status;
        }
        if (($hostCandidate['ok']??null)!==true
            || ($hostCandidate['native_genome_bound']??null)!==false
            || ($hostCandidate['recovery_bound']??null)!==false
            || ($hostCandidate['deployment_authorized']??null)!==false
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($hostCandidate['child_public_fingerprint']??''))) {
            $status['code']='HOST_CANDIDATE_NOT_VERIFIED';
            return $status;
        }
        $status['observed_native_genome_id']=$match[1];
        if ($match[1]==='kicom-0.9.26-g25r3') {
            $status['code']='NATIVE_GENOME_STILL_RELEASE_PARENT_UNBOUND';
        } else {
            $status['code']='NATIVE_GENOME_DISTINCTNESS_INSUFFICIENT';
        }
        return $status;
    }
}
