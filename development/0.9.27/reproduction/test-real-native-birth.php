<?php
declare(strict_types=1);
require_once __DIR__.'/KiComChildBirthReadiness.php';
$root=$argv[1]??'';
if (!preg_match('~^/tmp/kicom-membrane-os-[0-9]+$~D',$root)) {
    throw new RuntimeException('Only disposable Linux staging run permitted');
}
// The root basename contains the fixed test prefix; derive only from run id.
$run=substr($root,strlen('/tmp/kicom-membrane-os-'));
$native='/tmp/kicom-daughter-native-genome-'.$run.'.kcl';
if (is_link($native) || !is_file($native)
    || is_link($root.'/daughter-candidate.json')
    || !is_file($root.'/daughter-candidate.json')) {
    throw new RuntimeException('Original localhost genome and host candidate required');
}
$kcl=file_get_contents($native);
$record=json_decode(file_get_contents($root.'/daughter-candidate.json'),true,16,JSON_THROW_ON_ERROR);
if(!is_string($kcl)||!is_array($record))throw new RuntimeException('Lab evidence unreadable');
$report=KiComChildBirthReadiness::inspect($kcl,$record);
if(($report['code']??null)!=='NATIVE_GENOME_STILL_RELEASE_PARENT_UNBOUND'
    ||($report['observed_native_genome_id']??null)!=='kicom-0.9.26-g25r3'
    ||($report['daughter_born']??null)!==false
    ||($report['native_genome_bound']??null)!==false
    ||($report['deployment_authorized']??null)!==false) {
    throw new RuntimeException('FAIL actual R3 status was misrepresented as a born daughter');
}
echo "PASS real child localhost KCL genome remains unbound release parent\n";
if(str_contains($kcl,(string)$record['child_public_fingerprint'])) {
    throw new RuntimeException('FAIL public candidate fingerprint was falsely mixed into native genome');
}
echo "PASS independent daughter public fingerprint was not falsely represented by original R3 genome\n";
echo "KICOM_CHILD_REAL_NATIVE_GATE_TESTS_PASSED=2\n";
