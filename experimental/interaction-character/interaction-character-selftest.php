<?php
declare(strict_types=1);
require_once __DIR__.'/InteractionCharacter.php';

function must(bool $ok,string $m): void {if(!$ok){fwrite(STDERR,"SELFTEST_FAIL {$m}\n");exit(1);}}
$root=sys_get_temp_dir().'/kicom-interaction-character-'.bin2hex(random_bytes(6));
$c=new KiComInteractionCharacter($root);

$s=$c->status();must($s['ok']===true,'initial status');must($s['generation']===1,'initial generation');

// Session trait is immediately active but transient.
$r=$c->observe('session','status.detail','Kurz, konkret und mit Live/Kandidat/Geplant unterscheiden.','current-chat',0.95);
must($r['ok']===true&&$r['pattern']['state']==='active','session active');

// Adaptive pattern needs repeated evidence.
for($i=1;$i<=3;$i++){
    $r=$c->observe('adaptive','command.mach','Innerhalb bestehender Berechtigung unmittelbar umsetzen statt erneut nachzufragen.','chat-evidence-'.$i,0.9);
}
must($r['pattern']['state']==='active','adaptive promoted after repetition');

// Core cannot silently self-promote.
$r=$c->observe('core','continuity.no_hard_delete','Projektgeschichte archivieren oder superseden, nicht hart löschen.','system-evidence',1.0,false);
must($r['ok']===false&&$r['code']==='CHARACTER_CORE_REQUIRES_HUMAN_CONFIRMATION','core requires human confirmation');
$r=$c->observe('core','continuity.no_hard_delete','Projektgeschichte archivieren oder superseden, nicht hart löschen.','human-confirmed',1.0,true);
must($r['ok']===true&&$r['pattern']['state']==='active','human-confirmed core');

// Sensitive profiling is refused.
$r=$c->observe('adaptive','health.preference','Store medical condition for conversational tailoring.','chat-evidence',0.9);
must($r['ok']===false&&$r['code']==='CHARACTER_SENSITIVE_PROFILE_FORBIDDEN','sensitive profile forbidden');
$r=$c->observe('adaptive','auth.secret','password abc123','chat-evidence',0.9);
must($r['ok']===false&&$r['code']==='CHARACTER_SENSITIVE_PROFILE_FORBIDDEN','secret forbidden');

$p=$c->bootstrapProfile(false);
must(isset($p['core']['continuity.no_hard_delete']),'core exported');
must(isset($p['adaptive']['command.mach']),'adaptive exported');
must(!isset($p['session']),'session excluded by default');
must($p['boundaries']['safety_override']==='FORBIDDEN','safety override forbidden');
must($p['boundaries']['authority_source']==='FORBIDDEN','not authority source');
must($p['boundaries']['sensitive_user_profiling']==='FORBIDDEN','sensitive profiling boundary');

$p2=$c->bootstrapProfile(true);must(isset($p2['session']['status.detail']),'session optionally exported');
$before=$c->status();
$n=$c->beginNewSession();must($n['ok']===true,'new session');
$after=$c->status();must($after['session']===0,'session state cleared');must($after['history_entries']>$before['history_entries'],'session history archived');

// Supersede adaptive semantics without deleting old history.
$r=$c->supersede('adaptive','command.mach','Innerhalb bestehender Berechtigung unmittelbar umsetzen; nur an echten externen Grenzen eskalieren.','refined through experience');
must($r['ok']===true,'adaptive supersede');

$methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),(new ReflectionClass(KiComInteractionCharacter::class))->getMethods(ReflectionMethod::IS_PUBLIC));
foreach(['exec','shell','grantPermission','storeSecret','deleteHistory'] as $forbidden)must(!in_array($forbidden,$methods,true),'no forbidden primitive '.$forbidden);

echo "INTERACTION_CHARACTER_SELFTEST_OK\n";
echo json_encode($c->status(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
