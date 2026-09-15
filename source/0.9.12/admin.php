<?php
declare(strict_types=1);
if(!defined('KICOM_GUARDIAN_EMBEDDED')) define('KICOM_GUARDIAN_EMBEDDED',true);
require_once __DIR__ . '/guardian.php';
kicomImmuneGuardianMaybe(false,true);
require_once __DIR__ . '/lib.php';
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'; form-action 'self'");

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function loadConfig(): ?array { $f=kicomConfigFile(); if(!is_file($f))return null; $c=require $f; return is_array($c)?$c:null; }
function saveConfig(string $password): bool {
    $hash=password_hash($password,PASSWORD_DEFAULT);
    $php="<?php\nreturn ['password_hash' => ".var_export($hash,true).", 'created_at' => ".var_export(gmdate('c'),true)."];\n";
    return @file_put_contents(kicomConfigFile(),$php,LOCK_EX)!==false;
}
function csrf(): string { if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24)); return (string)$_SESSION['csrf']; }
function validCsrf(): bool { return isset($_POST['csrf'])&&hash_equals((string)($_SESSION['csrf']??''),(string)$_POST['csrf']); }
function proposals(): array {
    $rows=[];foreach(glob(kicomPendingDir().'/*.json')?:[] as $f){$x=json_decode((string)@file_get_contents($f),true);if(is_array($x))$rows[]=$x;}
    usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));return $rows;
}
function pendingFile(string $id): string { return kicomPendingDir().'/'.$id.'.json'; }

kicomEnsureStorage();kicomCleanupPending();
$config=loadConfig();$msg=(string)($_SESSION['flash_msg']??'');$err=(string)($_SESSION['flash_err']??'');unset($_SESSION['flash_msg'],$_SESSION['flash_err']);

if($config===null&&$_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['setup'])){
    $pw=(string)($_POST['password']??'');$pw2=(string)($_POST['password2']??'');
    if(strlen($pw)<12)$err='Passwort muss mindestens 12 Zeichen lang sein.';
    elseif($pw!==$pw2)$err='Passwörter stimmen nicht überein.';
    elseif(!saveConfig($pw))$err='Konfiguration konnte nicht gespeichert werden.';
    else{$config=loadConfig();$msg='Adminpasswort wurde eingerichtet.';}
}
if($config!==null&&empty($_SESSION['admin'])&&$_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['login'])){
    if(password_verify((string)($_POST['password']??''),(string)($config['password_hash']??''))){$_SESSION['admin']=true;session_regenerate_id(true);}else$err='Anmeldung fehlgeschlagen.';
}
if(isset($_GET['logout'])){session_destroy();header('Location: admin.php');exit;}

if(!empty($_SESSION['admin'])&&$_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])){
    if(!validCsrf())$err='CSRF-Prüfung fehlgeschlagen.';
    else{
        $action=(string)$_POST['action'];
        if(in_array($action,['approve','deny'],true)){
            $id=kicomSafeId((string)($_POST['id']??''));$file=$id===null?'':pendingFile($id);
            if($id===null||!is_file($file))$err='Vorschlag wurde nicht gefunden.';
            elseif($action==='deny'){@unlink($file);$msg='Vorschlag verworfen.';}
            else{
                $p=json_decode((string)file_get_contents($file),true);$kind=is_array($p)?(string)($p['kind']??'legacy_write'):'';
                if(!is_array($p))$err='Vorschlag ist ungültig.';
                elseif(str_starts_with($kind,'deploy_')){
                    $targetAlias=(string)($p['target']??'');
                    $targetInfo=kicomDeployTarget($targetAlias,false);
                    $proposalClass=(string)($p['target_class']??'test');
                    $currentClass=is_array($targetInfo)?(string)($targetInfo['class']??'test'):'test';
                    $targetClass=($proposalClass==='production'||$currentClass==='production')?'production':$currentClass;
                    if($targetClass==='production' && (!isset($_POST['production_ack']) || !hash_equals($targetAlias,trim((string)($_POST['production_alias']??''))))){
                        $err='Produktionsfreigabe verweigert: Checkbox bestätigen und Ziel-Alias exakt eingeben.';
                        $result=null;
                    } else $result=kicomApplyDeployProposal($p);
                    if($result===null){ /* error already set by production confirmation gate */ }
                    elseif(!$result['ok']){
                        if(!empty($result['rolled_back'])){@unlink($file);$err='Deployment-Healthcheck fehlgeschlagen; KiCom hat automatisch auf den vorherigen Stand zurückgerollt. Deployment-ID '.($result['deployment_id']??'');}
                        else{$err='Deployment konnte nicht übernommen werden: '.$result['code'];if(isset($result['current_target_sha256']))$err.=' (aktuell '.$result['current_target_sha256'].')';}
                    }else{@unlink($file);if(str_starts_with($kind,'deploy_package_'))$msg='Freigegeben → Paket-Deployment: '.($p['target']??'').' / '.($p['package_name']??'package').' · '.($p['files_count']??0).' Dateien · Deployment-ID '.($result['deployment_id']??'');else $msg='Freigegeben → Deployment: '.($p['target']??'').' / '.($p['dest_path']??'').' · Deployment-ID '.($result['deployment_id']??'');}
                } else {
                    $content=base64_decode((string)($p['content_b64']??''),true);
                    if($content===false)$err='Vorschlag ist ungültig.';
                    elseif($kind==='memory_archive_snapshot'){
                        $result=kicomMemoryArchiveSnapshotStore($content,'chatgpt_memory',(string)($p['label']??'ChatGPT memory snapshot'));
                        if(!$result['ok'])$err='Memory-Archiv konnte nicht geschrieben werden: '.($result['code']??'UNKNOWN');
                        else{@unlink($file);$msg='Freigegeben → ChatGPT-Memory archiviert: '.($result['snapshot']['id']??'').' · '.count($result['goals_ingested']??[]).' Ziel(e) übernommen.';}
                    } elseif($kind==='goal_proposal'){
                        $g=json_decode($content,true);if(!is_array($g))$err='Goal-Vorschlag ist ungültig.';else{$result=kicomGoalUpsert('chatgpt_memory',(string)($g['title']??''),(string)($g['description']??''),(string)($g['priority']??'medium'),(string)($g['evidence']??''),(string)($g['success_criteria']??''),(string)($g['source_ref']??''),true);if(!$result['ok'])$err='Goal-Vorschlag konnte nicht übernommen werden: '.($result['code']??'UNKNOWN');else{@unlink($file);$msg='Freigegeben → Ziel '.($result['goal']['id']??'').' aus ChatGPT-Memory.';}}
                    } elseif(str_starts_with($kind,'memory_')){
                        $resource=strtoupper((string)($p['resource']??''));$base=(string)($p['base_sha256']??'');$v=kicomValidateMemoryContent($resource,$content);
                        if($v['status']==='error')$err='Memory-Validierung fehlgeschlagen: '.$v['message'];
                        else{
                            $writeAction=$kind==='memory_rollback'?'rollback':'write';$result=kicomAtomicMemoryWrite($resource,$content,$writeAction,$base);
                            if(!$result['ok']){$err='Memory-Vorschlag konnte nicht übernommen werden: '.$result['code'];if(isset($result['current_sha256']))$err.=' (aktuell '.$result['current_sha256'].')';}
                            else{@unlink($file);$msg='Freigegeben → Project Memory: '.$resource.' · Revision '.($result['revision']?:'[nicht aufgezeichnet]');}
                        }
                    } else {
                        $path=kicomSafeRelativePath((string)($p['path']??''));
                        if($path===null)$err='Workspace-Vorschlag ist ungültig.';
                        else{
                            $v=kicomValidateContent($path,$content);
                            if($v['status']==='error')$err='Validierung fehlgeschlagen: '.$v['message'];
                            else{
                                $base=$p['base_sha256']??null;$expected=is_string($base)?$base:null;$writeAction=$kind==='rollback'?'rollback':($kind==='legacy_write'?'legacy_write':'write');
                                $result=kicomAtomicStageWrite($path,$content,$writeAction,$expected);
                                if(!$result['ok']){$err='Vorschlag konnte nicht übernommen werden: '.$result['code'];if(isset($result['current_sha256']))$err.=' (aktuell '.$result['current_sha256'].')';}
                                else{@unlink($file);$msg='Freigegeben → Workspace: '.$path.' · Revision '.($result['revision']?:'[nicht aufgezeichnet]');}
                            }
                        }
                    }
                }
            }
        } elseif($action==='totp_setup_begin'){
            $r=kicomTotpSetupBegin('Christoph');if(!$r['ok'])$err='FreeOTP-Einrichtung fehlgeschlagen: '.($r['code']??'UNKNOWN');else $msg='FreeOTP-Einrichtung gestartet. Öffne unten den FreeOTP-Link und bestätige anschließend einen aktuellen Code.';
        } elseif($action==='totp_setup_confirm'){
            $r=kicomTotpSetupConfirm((string)($_POST['totp_code']??''));if(!$r['ok'])$err='FreeOTP-Code abgelehnt: '.($r['code']??'UNKNOWN');else $msg='FreeOTP ist aktiv. Kritische Freigaben können jetzt direkt aus dem Chat erfolgen.';
        } elseif($action==='autonomy_revoke_sessions'){
            kicomAutonomyRevokeSessions();$msg='Alle Autonomie-Sitzungen wurden widerrufen.';
        } elseif($action==='autonomy_toggle'){
            $enabled=isset($_POST['autonomy_enabled']);if(kicomAutonomySetEnabled($enabled))$msg=$enabled?'Autonomy Envelope aktiviert.':'Autonomy Envelope pausiert.';else $err='Autonomy-Policy konnte nicht gespeichert werden.';
        } elseif($action==='save_update_channels'){
            $cfg=kicomUpdateChannelsLoad();
            $cfg['auto_install_green']=isset($_POST['auto_install_green']);
            $cfg['pull']['enabled']=isset($_POST['pull_enabled']);
            $cfg['pull']['feeds']=[
                ['name'=>'primary','enabled'=>isset($_POST['feed_primary_enabled']),'url'=>trim((string)($_POST['feed_primary_url']??''))],
                ['name'=>'mirror','enabled'=>isset($_POST['feed_mirror_enabled']),'url'=>trim((string)($_POST['feed_mirror_url']??''))]
            ];
            $cfg['push']['enabled']=isset($_POST['push_enabled']);
            if(kicomUpdateChannelsSave($cfg))$msg='Updatekanäle gespeichert.';
            else $err='Updatekanäle konnten nicht gespeichert werden. Nur HTTPS-Feed-URLs auf Port 443 sind zulässig.';
        } elseif($action==='rotate_push_key'){
            kicomUpdateRotatePushKey();$msg='Push-Schlüssel rotiert. Vorherige Clients müssen neu verbunden werden.';
        } elseif($action==='rotate_agent_key'){
            kicomUpdateRotateAgentKey();$msg='Update-Agent-Schlüssel rotiert. Cron-URL muss aktualisiert werden.';
        } elseif($action==='check_update_channels'){
            $r=kicomUpdatePullCheck(true,true);
            if(!$r['ok'])$err='Updatekanal-Prüfung fehlgeschlagen: '.($r['code']??'UNKNOWN');
            elseif(($r['code']??'')==='AUTO_INSTALLED_GREEN'){$_SESSION['flash_msg']='Grünes Update automatisch installiert.';header('Location: admin.php');exit;}
            elseif(($r['code']??'')==='STAGED_DECISION_REQUIRED')$msg='Update empfangen und geprüft. Risikoklasse '.strtoupper((string)($r['risk_class']??'?')).' – Entscheidung siehe oben.';
            else $msg='Updatekanäle geprüft: '.($r['code']??'OK');
        } elseif($action==='stage_self_update'){
            $f=$_FILES['update_zip']??null;
            if(!is_array($f)||($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)$err='Update-ZIP konnte nicht hochgeladen werden.';
            elseif(!is_uploaded_file((string)($f['tmp_name']??'')))$err='Update-Upload ist ungültig.';
            elseif(strtolower(pathinfo((string)($f['name']??''),PATHINFO_EXTENSION))!=='zip')$err='Nur ZIP-Pakete sind zulässig.';
            else{
                $r=kicomReceiveSelfUpdatePackage((string)$f['tmp_name'],(string)$f['name'],'admin_upload',true);
                if(!$r['ok'])$err='Update-Paket abgelehnt: '.($r['code']??'UNKNOWN');
                elseif(($r['code']??'')==='AUTO_INSTALLED_GREEN'){$_SESSION['flash_msg']='Grünes Update geprüft und automatisch installiert.';header('Location: admin.php');exit;}
                else{$s=$r['staged']??$r;$msg='Update verifiziert und bereit: KiCom '.($s['from_version']??KICOM_VERSION).' → '.($s['to_version']??'?').' · Risiko '.strtoupper((string)($s['risk_class']??'?'));}
            }
        } elseif($action==='discard_self_update'){
            @unlink(kicomSelfUpdatePendingFile());$msg='Bereitgestelltes Self-Update verworfen.';
        } elseif($action==='install_self_update'){
            $pending=kicomSelfUpdatePending();$confirm=trim((string)($_POST['update_version_confirm']??''));$kernelConfirm=trim((string)($_POST['kernel_confirm']??''));
            $risk=(string)($pending['risk_class']??'red');
            if($pending===null)$err='Kein Self-Update bereitgestellt.';
            elseif($risk==='red'&&(!isset($_POST['update_ack'])||!hash_equals((string)($pending['to_version']??''),$confirm)))$err='Rotes Update nicht freigegeben: Checkbox bestätigen und Zielversion exakt eingeben.';
            elseif($risk==='yellow'&&!isset($_POST['yellow_ack']))$err='Gelbes Update benötigt eine bewusste Ein-Klick-Freigabe.';
            elseif(!empty($pending['kernel_update'])&&(!isset($_POST['kernel_ack'])||!hash_equals('KERNEL '.(string)$pending['to_version'],$kernelConfirm)))$err='Recovery-Kernel-Update nicht freigegeben: zusätzliche Kernel-Bestätigung fehlt.';
            else{$r=kicomApplySelfUpdate($pending);if(!$r['ok'])$err='Self-Update fehlgeschlagen: '.$r['code'];else{$_SESSION['flash_msg']='KiCom Self-Update erfolgreich: '.$r['from_version'].' → '.$r['to_version'].' · Historie '.$r['history_id'];header('Location: admin.php');exit;}}
        } elseif($action==='rollback_self_update'){
            $history=(string)($_POST['history_id']??'');$confirm=trim((string)($_POST['rollback_version_confirm']??''));
            $rows=kicomSelfUpdateHistory();$source=null;foreach($rows as $row)if(hash_equals((string)($row['id']??''),$history)){$source=$row;break;}
            if(!is_array($source))$err='Self-Update-Historie nicht gefunden.';
            elseif(!isset($_POST['rollback_ack'])||!hash_equals((string)($source['from_version']??''),$confirm))$err='Rollback nicht freigegeben: Checkbox bestätigen und vorherige Version exakt eingeben.';
            else{$r=kicomRollbackSelfUpdate($history);if(!$r['ok'])$err='Self-Update-Rollback fehlgeschlagen: '.$r['code'];else{$msg='Self-Update-Rollback erfolgreich: '.$r['from_version'].' → '.$r['to_version'];}}
        } elseif($action==='living_scan'){
            $r=kicomLivingStatus();$msg='Immunsystem-Scan: '.(!empty($r['healthy'])?'gesund':'Abweichung erkannt').' · Drift '.count($r['drift']??[]).' · unbekannt '.count($r['unknown']??[]);
        } elseif($action==='living_heal'){
            if(!defined('KICOM_RECOVERY_EMBEDDED'))define('KICOM_RECOVERY_EMBEDDED',true);require_once __DIR__.'/recovery.php';$r=rkHeal();$msg=$r['ok']?'Selbstheilung abgeschlossen: '.count($r['repaired']??[]).' Komponente(n) repariert.':'Selbstheilung blockiert/fehlgeschlagen: '.($r['code']??'Fehler');
        } elseif($action==='living_quarantine_unknown'){
            if(!defined('KICOM_RECOVERY_EMBEDDED'))define('KICOM_RECOVERY_EMBEDDED',true);require_once __DIR__.'/recovery.php';$r=rkUnknownQuarantine();$msg='Quarantäne abgeschlossen: '.count($r['files']??[]).' unbekannte Datei(en) isoliert.';
        } elseif($action==='rotate_guardian_key'){
            $key=kicomRotateGuardianKey();if($key===null)$err='Guardian-Key konnte nicht rotiert werden.';else$msg='Guardian-Key rotiert. Neue Cron-URL wird unten angezeigt.';
        } elseif($action==='create_goal'){
            $r=kicomGoalUpsert('human',(string)($_POST['goal_title']??''),(string)($_POST['goal_description']??''),(string)($_POST['goal_priority']??'medium'),(string)($_POST['goal_evidence']??''),(string)($_POST['goal_success']??''),'admin:'.gmdate('YmdHis'),true);if(!$r['ok'])$err='Ziel konnte nicht angelegt werden: '.($r['code']??'UNKNOWN');else $msg='Menschliches Ziel angelegt: '.($r['goal']['id']??'');
        } elseif($action==='archive_goal'){
            $r=kicomGoalArchive((string)($_POST['goal_id']??''),(string)($_POST['goal_reason']??'Manuell archiviert'));if(!$r['ok'])$err='Ziel konnte nicht archiviert werden: '.($r['code']??'UNKNOWN');else $msg='Ziel archiviert – Historie bleibt vollständig erhalten.';
        } elseif($action==='toggle_evolution_autonomy'){
            $enable=(string)($_POST['evolution_enabled']??'0')==='1';
            if(!kicomEvolutionSetEnabled($enable))$err='Evolution-Autonomie konnte nicht geändert werden.';else $msg=$enable?'Autonome Evolution fortgesetzt.':'Autonome Evolution pausiert.';
        } elseif($action==='run_evolution_tick'){
            $r=kicomEvolutionAutonomousTick();$msg='Evolution-Lauf: '.(string)($r['code']??'UNKNOWN');
        } elseif($action==='stage_evolution_candidate'){
            $f=$_FILES['candidate_zip']??null;
            if(!is_array($f)||($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)$err='Candidate-ZIP konnte nicht hochgeladen werden.';
            elseif(!is_uploaded_file((string)($f['tmp_name']??'')))$err='Candidate-Upload ist ungültig.';
            elseif(strtolower(pathinfo((string)($f['name']??''),PATHINFO_EXTENSION))!=='zip')$err='Nur ZIP-Pakete sind zulässig.';
            else{$r=kicomEvolutionStageCandidate((string)$f['tmp_name'],(string)$f['name']);if(!$r['ok'])$err='Candidate abgelehnt: '.$r['code'];else$msg='Candidate '.$r['id'].' bewertet: Fitness '.$r['fitness']['percent'].'% · '.($r['fitness']['passed']?'FIT':'NICHT FIT');}
        } elseif($action==='promote_evolution_candidate'){
            $id=(string)($_POST['candidate_id']??'');$confirm=trim((string)($_POST['candidate_version_confirm']??''));$c=kicomEvolutionCandidate($id);
            if($c===null)$err='Candidate nicht gefunden.';
            elseif(!isset($_POST['candidate_ack'])||!hash_equals((string)($c['to_version']??''),$confirm))$err='Promotion nicht freigegeben: Version exakt eingeben und bestätigen.';
            else{$r=kicomEvolutionPromote($id);if(!$r['ok'])$err='Promotion fehlgeschlagen: '.$r['code'];else$msg='Candidate in den Self-Update-Controller übernommen: '.$r['from_version'].' → '.$r['to_version'];}
        } elseif($action==='delete_workspace'){
            $path=kicomSafeRelativePath((string)($_POST['path']??''));
            if($path===null)$err='Ungültiger Pfad.';else{$r=kicomDeleteStageWithHistory($path);if($r['ok'])$msg='Workspace-Datei gelöscht; Verlauf bleibt erhalten: '.$path;else$err='Löschen fehlgeschlagen: '.$r['code'];}
        } elseif($action==='save_deploy_target'){
            $alias=kicomDeployTargetAlias((string)($_POST['alias']??''));$root=kicomDeployRoot((string)($_POST['root']??''));$health=kicomValidateHealthUrl((string)($_POST['health_url']??''));$label=trim((string)($_POST['label']??''));$class=kicomDeployClass((string)($_POST['class']??'test'));
            if($alias===null||$root===null||$health===null||$class===null)$err='Deployment-Ziel ungültig. Root muss ein existierendes Verzeichnis außerhalb von KiCom sein; Health-URL muss leer oder HTTPS sein.';
            elseif($class==='production'&&$health==='')$err='Produktionsziele benötigen zwingend eine HTTPS-Health-URL.';
            elseif($class==='production'&&!isset($_POST['production_target_ack']))$err='Produktionsziel nicht gespeichert: Sicherheitsbestätigung fehlt.';
            else{$targets=kicomLoadDeployTargets();$targets[$alias]=['root'=>$root,'health_url'=>$health,'enabled'=>isset($_POST['enabled']),'label'=>$label!==''?$label:$alias,'class'=>$class];if(kicomSaveDeployTargets($targets))$msg='Deployment-Ziel gespeichert: '.$alias.' · Klasse '.$class;else$err='Deployment-Ziel konnte nicht gespeichert werden.';}
        } elseif($action==='delete_deploy_target'){
            $alias=kicomDeployTargetAlias((string)($_POST['alias']??''));$targets=kicomLoadDeployTargets();if($alias===null||!isset($targets[$alias]))$err='Deployment-Ziel nicht gefunden.';else{unset($targets[$alias]);if(kicomSaveDeployTargets($targets))$msg='Deployment-Ziel entfernt: '.$alias;else$err='Deployment-Ziel konnte nicht entfernt werden.';}
        }
    }
}
$logged=!empty($_SESSION['admin']);
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KiCom Control Plane</title><meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex"><link rel="stylesheet" href="assets/control-plane.css">
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:1080px;margin:36px auto;padding:0 18px;background:#f5f6f8;color:#1d2430}main,section{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:22px;margin:18px 0}h1,h2,h3{margin-top:0}input,button,select{font:inherit;padding:10px 12px;border-radius:8px;border:1px solid #b8c1ce}button{cursor:pointer;background:#fff}.ok{background:#eaf7ee;padding:10px;border-radius:8px}.err{background:#fdecec;padding:10px;border-radius:8px}.warn{background:#fff7df;padding:10px;border-radius:8px}.muted{color:#667085;font-size:.92rem}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.danger{border-color:#d44}.approve{border-color:#2a7}.file{border-top:1px solid #eee;padding:16px 0}.tag{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef2f7;font-size:.8rem;margin-right:6px}.memorytag{background:#ede9fe;color:#5b21b6}.prodtag{background:#fee2e2;color:#991b1b}.prodcard{border-color:#ef4444}.prodconfirm{background:#fff1f2;border:1px solid #ef4444;border-radius:8px;padding:10px;margin:8px 0}pre{white-space:pre-wrap;word-break:break-word;background:#f6f7f9;padding:12px;border-radius:8px;max-height:420px;overflow:auto}.diff .add{background:#eaf7ee}.diff .del{background:#fdecec}code{word-break:break-all}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.card{border:1px solid #e4e7ec;border-radius:10px;padding:12px}</style></head><body>
<main><h1>KiCom <small><?=h(KICOM_VERSION)?></small></h1><p class="muted">Control Plane für Menschen. KiCom erledigt deterministische Prüfungen, Heilung, Backups, Updates und Rollbacks möglichst selbst. Hier erscheinen zuerst nur Zustand und Entscheidungen, die wirklich Aufmerksamkeit brauchen.</p>
<?php if($msg):?><p class="ok"><?=h($msg)?></p><?php endif;?><?php if($err):?><p class="err"><?=h($err)?></p><?php endif;?>
<?php if($config===null):?><h2>Ersteinrichtung</h2><p>Lege ein lokales KiCom-Adminpasswort fest. Es wird nur als Passwort-Hash gespeichert.</p><form method="post"><input type="hidden" name="setup" value="1"><p><input type="password" name="password" placeholder="Mindestens 12 Zeichen" required minlength="12"></p><p><input type="password" name="password2" placeholder="Wiederholen" required minlength="12"></p><button>Adminpasswort einrichten</button></form>
<?php elseif(!$logged):?><h2>Anmeldung</h2><form method="post"><input type="hidden" name="login" value="1"><input type="password" name="password" required autofocus> <button>Anmelden</button></form>
<?php else:?><p><a href="?logout=1">Abmelden</a></p></main>
<?php
$cpLs=kicomLivingStatus();
$cpUs=kicomUpdateChannelsPublicStatus();
$cpPs=proposals();
$cpSu=kicomSelfUpdatePending();
$cpHealthy=!empty($cpLs['healthy'])&&!empty($cpLs['trusted'])&&!empty($cpLs['lkg_ok'])&&count($cpLs['drift']??[])===0&&count($cpLs['unknown']??[])===0;
$cpRisk=(string)($cpSu['risk_class']??'');
$cpEvo=kicomEvolutionStatus();
$cpEvoAlert=is_array($cpEvo['state']['alert']??null)?$cpEvo['state']['alert']:null;
$cpAttention=[];
if(!$cpHealthy)$cpAttention[]='Systemzustand prüfen';
if(count($cpPs)>0)$cpAttention[]=count($cpPs).' offene Vorschlag'.(count($cpPs)===1?'':'e');
if($cpSu!==null)$cpAttention[]='Update '.(string)($cpSu['to_version']??'?').' ('.strtoupper($cpRisk!==''?$cpRisk:'ROT').')';
if($cpEvoAlert!==null)$cpAttention[]='Evolution pausiert: '.(string)($cpEvoAlert['code']??'Sicherheitsereignis');
?>
<section class="attention <?=$cpHealthy&&count($cpAttention)===0?'good':($cpHealthy?'warn':'bad')?>">
  <div class="big-state"><?=$cpHealthy?'GESUND':'AUFMERKSAMKEIT NÖTIG'?></div>
  <?php if(!$cpAttention):?><p>Keine menschliche Aktion erforderlich. KiCom überwacht, heilt und protokolliert selbstständig.</p>
  <?php else:?><p><?=h(implode(' · ',$cpAttention))?></p><?php endif;?>
</section>
<section>
  <h2>Übersicht</h2>
  <div class="control-grid">
    <div class="status-card <?=$cpHealthy?'status-good':'status-bad'?>"><strong>System</strong><span class="big-state"><?=$cpHealthy?'Gesund':'Abweichung'?></span><p class="muted">Genome <?=h((string)($cpLs['genome_id']??'?'))?><br>Drift <?=count($cpLs['drift']??[])?> · Unbekannt <?=count($cpLs['unknown']??[])?><br>LKG <?=!empty($cpLs['lkg_ok'])?'bereit':'nicht bereit'?></p></div>
    <div class="status-card status-info"><strong>Immunsystem</strong><span class="big-state">Automatisch</span><p class="muted">Bekannte Schäden werden selbst geheilt. Parallelheilung ist gesperrt; legitime Updates setzen einen Maintenance-Lock.</p></div>
    <div class="status-card <?=($cpSu===null)?'status-good':($cpRisk==='red'?'status-bad':'status-warn')?>"><strong>Updates</strong><?php if($cpSu===null):?><span class="big-state">Kein Eingriff</span><p class="muted">Grüne Updates dürfen automatisch installiert werden.</p><?php else:?><span class="big-state"><?=h((string)($cpSu['to_version']??'?'))?></span><p><span class="tag risk-<?=h($cpRisk?:'red')?>"><?=h(strtoupper($cpRisk?:'red'))?></span></p><p class="muted">Quelle <?=h((string)($cpSu['source']??'unbekannt'))?></p><?php endif;?></div>
    <div class="status-card status-info"><strong>Mensch</strong><span class="big-state"><?=count($cpPs)+(int)($cpSu!==null&&$cpRisk!=='green')?> Entscheidung(en)</span><p class="muted">Nur gelbe/rote Updates und fachliche Vorschläge brauchen Aufmerksamkeit. Technische Details liegen in der Expertenansicht.</p></div>
  </div>
</section>
<section>
  <h2>Updates & Entlastung</h2>
  <div class="control-grid">
    <div class="channel"><strong>Pull-Kanal</strong><p class="muted">KiCom holt Releases selbst von allowlisteten HTTPS-Feeds. Primär- und Mirror-Feed können parallel konfiguriert werden.</p><div class="state"><?=!empty($cpUs['pull_enabled'])?'aktiv':'aus'?> · letzter Status <?=h((string)$cpUs['last_code'])?></div><?php if(!empty($cpUs['last_check_at'])):?><p class="muted">Letzte Prüfung <?=h((string)$cpUs['last_check_at'])?></p><?php endif;?><?php foreach($cpUs['feeds']??[] as $cf):?><?php if(empty($cf['enabled']))continue;$checked=(string)($cf['checked_at']??'');$code=(string)($cf['code']??'not_checked');$ok=!empty($cf['ok']);?><div class="feed-line <?=$ok?'feed-ok':'feed-bad'?>"><strong><?=h(ucfirst((string)($cf['name']??'feed')))?></strong><span><?=$ok?'✓':'✕'?></span><span><?=h((string)($cf['host']??''))?></span><span><?=($cf['http_code']??0)>0?'HTTP '.h((string)$cf['http_code']):'kein HTTP'?></span><span><?=!empty($cf['json_valid'])?'JSON gültig':'JSON nicht bestätigt'?></span><span><?=h($code)?></span><?php if($checked!==''):?><small><?=h($checked)?></small><?php endif;?></div><?php endforeach;?></div>
    <div class="channel"><strong>Push-/Inbox-Kanal</strong><p class="muted">Autorisierte Clients können ein Paket direkt einspeisen. Es landet trotzdem zuerst im gleichen Verifikations- und Risikopfad.</p><div class="state"><?=!empty($cpUs['push_enabled'])?'aktiv':'aus'?></div></div>
  </div>
  <div class="action-row" style="margin-top:14px">
    <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="check_update_channels"><button class="primary">Updatekanäle jetzt prüfen</button></form>
  </div>
  <?php if($cpSu!==null):?>
    <div class="<?=$cpRisk==='red'?'err':($cpRisk==='yellow'?'warn':'ok')?>" style="margin-top:14px">
      <strong>Update <?=h((string)$cpSu['to_version'])?> · <?=h(strtoupper($cpRisk?:'red'))?></strong>
      <p class="muted"><?=h(implode(' · ',array_map('strval',$cpSu['risk_reasons']??[])))?></p>
      <?php if($cpRisk==='green'):?>
        <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="install_self_update"><button class="primary">Jetzt installieren</button></form>
      <?php elseif($cpRisk==='yellow'):?>
        <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="install_self_update"><input type="hidden" name="yellow_ack" value="1"><button class="primary">Geprüftes Update installieren</button></form>
      <?php else:?><p><strong>Rote Änderung:</strong> bewusste Bestätigung nur in der Expertenansicht.</p><?php endif;?>
    </div>
  <?php endif;?>
</section>
<section><h2>Offene Vorschläge</h2><?php $ps=proposals();if(!$ps):?><p>Keine offenen Vorschläge.</p><?php else:foreach($ps as $p):
$kind=(string)($p['kind']??'legacy_write');$isMemory=str_starts_with($kind,'memory_');$isDeploy=str_starts_with($kind,'deploy_');$isPackageDeploy=$isDeploy&&str_starts_with($kind,'deploy_package_');$raw=$isDeploy?false:base64_decode((string)($p['content_b64']??''),true);$base=(string)($p['base_sha256']??'');
if($isDeploy){$targetAlias=(string)($p['target']??'?');$targetInfo=kicomDeployTarget($targetAlias,false);$proposalClass=(string)($p['target_class']??'test');$currentClass=is_array($targetInfo)?(string)($targetInfo['class']??'test'):'test';$targetClass=($proposalClass==='production'||$currentClass==='production')?'production':$currentClass;if($isPackageDeploy){$label='Deploy-Paket '.$targetAlias.' → '.(string)($p['package_name']??'package');$current='PACKAGE';$expected='PACKAGE';$conflict=kicomDeployPackageProposalConflict($p);$old='';$val=['status'=>'ok','message'=>'PACKAGE_DEPLOYMENT_GATED'];}else{$label='Deploy '.$targetAlias.' → '.(string)($p['dest_path']??'?');$current=kicomTargetHash($targetAlias,(string)($p['dest_path']??''));$expected=(string)($p['target_base_sha256']??'');$conflict=$expected!==''&&$current!==$expected;$old='';$val=['status'=>'ok','message'=>'DEPLOYMENT_GATED'];}}
elseif($isMemory){$resource=strtoupper((string)($p['resource']??''));$res=kicomReadMemoryResource($resource);$label=$resource;$current=$res['sha256']??'';$old=$res['raw']??'';$val=$raw!==false?kicomValidateMemoryContent($resource,$raw):['status'=>'error','message'=>'INVALID'];$conflict=$base!==''&&$current!==$base;}
else{$path=kicomSafeRelativePath((string)($p['path']??''));$label=(string)($p['path']??'[ungültig]');$current=$path!==null?kicomCurrentHash($path):'';$old=$path!==null?(kicomReadStage($path)??''):'';$val=($raw!==false&&$path!==null)?kicomValidateContent($path,$raw):['status'=>'error','message'=>'INVALID'];$conflict=$base!==''&&$current!==$base;}
$diff=(!$isDeploy&&$raw!==false&&strlen((string)$old)<=KICOM_MAX_READ_BYTES)?kicomUnifiedDiff((string)$old,$raw,500):['ok'=>false];?>
<div class="file"><h3><?=h($label)?></h3><p><span class="tag <?=$isMemory?'memorytag':''?>"><?=h($kind)?></span><span class="tag"><?=h((string)($p['bytes']??0))?> Bytes</span><span class="tag">Validation: <?=h($val['message'])?></span></p><div class="muted">ID <?=h((string)($p['id']??''))?> · Vorschlag SHA-256 <code><?=h((string)($p['sha256']??''))?></code></div>
<?php if($base!==''):?><div class="muted">Basis <code><?=h($base)?></code> · aktuell <code><?=h((string)$current)?></code></div><?php endif;?>
<?php if($conflict):?><p class="err">Konflikt: Der kanonische Ausgangsstand hat sich seit Erstellung des Vorschlags geändert. Freigabe wird serverseitig blockiert.</p><?php endif;?>
<?php if($isDeploy&&!$isPackageDeploy):?><p class="muted">Workspace: <code><?=h((string)($p['workspace_path']??'-'))?></code> · Zielbasis: <code><?=h((string)($p['target_base_sha256']??''))?></code></p><?php elseif($isPackageDeploy):?><p class="muted">Manifest: <code><?=h((string)($p['manifest_path']??'-'))?></code> · Manifest SHA-256: <code><?=h((string)($p['manifest_sha256']??''))?></code> · Dateien: <?=h((string)($p['files_count']??0))?></p><?php if(is_array($p['files']??null)):?><ul><?php foreach($p['files'] as $pf):?><li><code><?=h((string)($pf['workspace_path']??''))?></code> → <code><?=h((string)($pf['dest_path']??''))?></code></li><?php endforeach;?></ul><?php endif;?><?php endif;?>
<?php if(isset($p['source_revision'])):?><p class="muted">Rollback-Ziel: <?=h((string)$p['source_revision'])?></p><?php endif;?>
<?php if(($diff['ok']??false)):?><pre class="diff"><?php foreach($diff['lines'] as $line):?><span class="<?=str_starts_with($line,'+')&&!str_starts_with($line,'+++')?'add':(str_starts_with($line,'-')&&!str_starts_with($line,'---')?'del':'')?>"><?=h($line)?></span>
<?php endforeach;?></pre><?php elseif($raw!==false):?><pre><?=h($raw)?></pre><?php endif;?>
<div class="row"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=h((string)$p['id'])?>"><?php if($isDeploy&&isset($targetClass)&&$targetClass==='production'):?><div class="prodconfirm"><strong>PRODUCTION</strong><br><label><input type="checkbox" name="production_ack" value="1" required> Produktionsänderung bewusst freigeben</label><br><label>Ziel-Alias zur Bestätigung: <input name="production_alias" placeholder="<?=h((string)($p['target']??''))?>" required autocomplete="off"></label></div><?php endif;?><button class="approve" <?=$conflict?'disabled':''?>>Freigeben → <?=$isDeploy?'Deployment':($isMemory?'Project Memory':'Workspace')?></button></form><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="deny"><input type="hidden" name="id" value="<?=h((string)$p['id'])?>"><button class="danger">Verwerfen</button></form></div></div>
<?php endforeach;endif;?></section>
<details class="expert-shell"><summary>Expertenansicht & Einstellungen</summary><div class="expert-body">
<section><h2>Living Architecture · Genome & Heilung</h2>
<?php $ls=kicomLivingStatus();$gx=kicomGenomeCurrent();$exp=kicomLivingExperience();$ins=kicomLivingInsights();$gkey=kicomGuardianPlainKey();?>
<div class="grid">
<div class="card"><strong><?=!empty($ls['healthy'])?'GESUND':'ABWEICHUNG'?></strong><p class="muted">Genome <code><?=h((string)($ls['genome_id']??'?'))?></code><br>Version <?=h((string)($ls['version']??'?'))?><br>Vertrauen: <?=!empty($ls['trusted'])?'verankert':'NEIN'?><br>LKG: <?=!empty($ls['lkg_ok'])?'OK':'unvollständig'?><br>Drift <?=count($ls['drift']??[])?> · unbekannt <?=count($ls['unknown']??[])?></p></div>
<div class="card"><strong>Erfahrung & Lernsignale</strong><p class="muted">Events: <?=h((string)($exp['events']??0))?><br>Insights: <?=h((string)count($ins['insights']??[]))?><br>Candidates: <?=h((string)($ls['candidates']??0))?><br>Kernel-Revision: <?=h((string)($gx['kernel_revision']??'?'))?></p></div>
<div class="card"><strong>Recovery</strong><p class="muted"><a href="recovery.php">Separaten Recovery Kernel öffnen</a><br>Der Recovery-Zugang bleibt unabhängig vom normalen Core nutzbar.</p></div>
</div>
<?php foreach($ls['drift']??[] as $d):?><p class="err"><strong><?=h((string)$d['path'])?></strong> · erwartet <code><?=h((string)$d['expected'])?></code> · ist <code><?=h((string)$d['actual'])?></code> · Auto-Heal <?=$d['auto_heal']?'ja':'NEIN (Kernel)'?></p><?php endforeach;?>
<?php foreach($ls['unknown']??[] as $u):?><p class="warn">Unbekanntes ausführbares/steuerndes Artefakt: <code><?=h((string)$u)?></code></p><?php endforeach;?>
<div class="row"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button name="action" value="living_scan">Immunsystem scannen</button><button class="approve" name="action" value="living_heal">Bekannte Schäden heilen</button><button class="danger" name="action" value="living_quarantine_unknown">Unbekanntes isolieren</button></form></div>
<?php if(!empty($ins['insights'])):?><h3>Lernsignale / Hypothesen</h3><div class="grid"><?php foreach(array_slice($ins['insights'],0,6) as $in):?><div class="card"><strong><?=h((string)$in['kind'])?></strong> <span class="tag"><?=h((string)$in['priority'])?></span><p class="muted"><b>Evidenz:</b> <?=h((string)$in['evidence'])?><br><b>Hypothese:</b> <?=h((string)$in['hypothesis'])?><br><b>Vorschlag:</b> <?=h((string)$in['suggested_action'])?></p></div><?php endforeach;?></div><?php endif;?>
<h3>Guardian / automatische Heilung</h3><p class="muted">Der Immune Guardian läuft automatisch vor normalen Core-Requests (gedrosselt und mit Healing-Lock). Zusätzlich kann der URL-Cron im Leerlauf prüfen. Bekannte Schäden werden aus dem verankerten LKG restauriert; unbekannte ausführbare oder steuernde Artefakte werden reversibel quarantänisiert. Während legitimer Self-Updates pausiert das Immunsystem per Maintenance-Lock.</p>
<?php if($gkey):?><div class="card"><code><?=h('https://'.(string)($_SERVER['HTTP_HOST']??'HOST').rtrim(dirname((string)($_SERVER['SCRIPT_NAME']??'/admin.php')),'/').'/guardian.php?key='.$gkey)?></code></div><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button name="action" value="rotate_guardian_key">Guardian-Key rotieren</button></form>
<h3>Goal Layer &amp; Gedächtnisarchiv</h3>
<?php $goalStore=kicomGoals(true);$goalRows=array_reverse($goalStore['goals']??[]);$openGoalRows=array_values(array_filter($goalRows,fn($g)=>is_array($g)&&($g['status']??'open')==='open'));$ma=kicomMemoryArchiveStatus();$ml=kicomMemoryArchiveList(5);?>
<p class="muted">Ziele können aus <strong>Mensch</strong>, <strong>ChatGPT-Memory</strong> oder <strong>Systemerfahrung</strong> stammen. Ein Ziel erweitert niemals Berechtigungen. Historie wird nicht hart gelöscht: erledigte oder überholte Ziele werden archiviert bzw. superseded.</p>
<div class="grid"><div class="card"><strong>Offene Ziele</strong><span class="big-state"><?=h((string)count($openGoalRows))?></span><p class="muted">Gesamt <?=h((string)count($goalRows))?> · Quellen bleiben nachvollziehbar.</p></div><div class="card"><strong>Memory-Snapshots</strong><span class="big-state"><?=h((string)$ma['count'])?></span><p class="muted"><?=h((string)$ma['bytes'])?> Bytes archiviert · Policy: archive-never-hard-delete.</p></div></div>
<details><summary>Menschliches Ziel anlegen</summary><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="create_goal"><label>Titel<input name="goal_title" maxlength="180" required></label><label>Beschreibung<textarea name="goal_description" maxlength="1200" required></textarea></label><label>Priorität<select name="goal_priority"><option>low</option><option selected>medium</option><option>high</option><option>critical</option></select></label><label>Evidenz<textarea name="goal_evidence" maxlength="1200"></textarea></label><label>Erfolgskriterium<textarea name="goal_success" maxlength="1200"></textarea></label><button class="approve">Ziel anlegen</button></form></details>
<?php if($openGoalRows):?><details open><summary>Offene Ziele</summary><div class="grid"><?php foreach(array_slice($openGoalRows,0,12) as $g):?><div class="card"><strong><?=h((string)($g['title']??''))?></strong> <span class="tag"><?=h((string)($g['priority']??''))?></span><p class="muted">Quelle <?=h((string)($g['source']??''))?> · ID <?=h((string)($g['id']??''))?><br><?=h((string)($g['description']??''))?></p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="goal_id" value="<?=h((string)($g['id']??''))?>"><input type="hidden" name="goal_reason" value="Manuell archiviert"><button name="action" value="archive_goal">Archivieren</button></form></div><?php endforeach;?></div></details><?php endif;?>
<?php if($ml):?><details><summary>Letzte Memory-Snapshots</summary><?php foreach($ml as $m):?><div class="card"><strong><?=h((string)($m['id']??''))?></strong><p class="muted"><?=h((string)($m['created_at']??''))?> · <?=h((string)($m['payload_bytes']??0))?> Bytes · SHA-256 <?=h((string)($m['payload_sha256']??''))?><br>Inhalt liegt ausschließlich im geschützten var-Bereich und wird nicht über das öffentliche KCL ausgegeben.</p></div><?php endforeach;?></details><?php endif;?>
<h3>Autonome Evolution</h3>
<?php $ev=kicomEvolutionStatus();$evc=$ev['config'];$evs=$ev['state'];$evr=$ev['latest_report'];$evEnabled=!empty($evc['enabled']);?>
<p class="muted">KiCom beobachtet Erfahrungssignale, bildet Evolutionsziele, bewertet Candidates und darf vollständig geprüfte <strong>grüne</strong> Evolutionen selbst übernehmen. Gelb und Rot bleiben bewusst human-gated. Produktversion und Evolutionsgeneration sind getrennt.</p>
<div class="grid">
  <div class="card"><strong>Autonomie</strong><span class="big-state"><?=$evEnabled?'AKTIV':'PAUSIERT'?></span><p class="muted">Letzter Lauf <?=h((string)($evs['last_tick_at']??'noch keiner'))?><br>Status <?=h((string)($evs['last_tick_code']??'never'))?></p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="toggle_evolution_autonomy"><input type="hidden" name="evolution_enabled" value="<?=$evEnabled?'0':'1'?>"><button class="<?=$evEnabled?'danger':'approve'?>"><?=$evEnabled?'Autonomie pausieren':'Autonomie fortsetzen'?></button></form></div>
  <div class="card"><strong>Generation</strong><span class="big-state"><?=h((string)($evs['generation']??0))?></span><p class="muted">Epoch <?=h((string)($evs['epoch']??0))?> · Bericht alle <?=h((string)($evc['report_every']??10))?> erfolgreichen Generationen<br>Nächster Bericht bei Generation <?=h((string)($ev['next_report_generation']??10))?></p></div>
  <div class="card"><strong>Evolutionsziele</strong><span class="big-state"><?=h((string)($ev['open_goals']??0))?></span><p class="muted">Aus wiederkehrenden Fehlern und Insights abgeleitet. Ziele sind Arbeitsaufträge an den Reasoning-Cortex, keine ausführbaren Änderungen.</p></div>
</div>
<?php if(is_array($evs['alert']??null)):?><div class="prodconfirm"><strong>Sicherheitsstopp:</strong> <?=h((string)($evs['alert']['code']??'UNKNOWN'))?>. Die Evolution verändert nichts, bis Genome/LKG/Drift/Unknown wieder sauber sind.</div><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button name="action" value="run_evolution_tick">Evolution jetzt prüfen</button></form>
<?php if($evr):?><details><summary>Letzter Epoch-Bericht</summary><div class="card"><strong>Epoch <?=h((string)$evr['epoch'])?> · Generationen <?=h((string)$evr['generation_start'])?>–<?=h((string)$evr['generation_end'])?></strong><p class="muted">Version <?=h((string)$evr['product_version'])?> · Genome <?=h((string)$evr['genome_id'])?><br>healthy <?=!empty($evr['health']['healthy'])?'ja':'nein'?> · trusted <?=!empty($evr['health']['trusted'])?'ja':'nein'?> · LKG <?=!empty($evr['health']['lkg_ok'])?'OK':'Fehler'?> · Drift <?=h((string)$evr['health']['drift'])?> · Unknown <?=h((string)$evr['health']['unknown'])?></p></div></details><?php endif;?>
<h4>Evolution Lab / Candidate-Inbox</h4><p class="muted">Candidates bleiben bis zur Prüfung nicht ausführbar. FIT + Grün darf der autonome Loop selbst übernehmen; Gelb/Rot wartet auf eine menschliche Entscheidung. Der externe Reasoning-Cortex kann weiterhin neue Candidates erzeugen.</p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="stage_evolution_candidate"><label>Candidate-Release-ZIP<br><input type="file" name="candidate_zip" accept=".zip,application/zip" required></label> <button>Candidate prüfen</button></form>
<?php $ecs=kicomEvolutionCandidates();if($ecs):?><div class="grid"><?php foreach(array_slice($ecs,0,12) as $c):?><div class="card"><strong><?=h((string)($c['from_version']??'?'))?> → <?=h((string)($c['to_version']??'?'))?></strong> <span class="tag"><?=h((string)($c['status']??''))?></span><?php if(!empty($c['risk_class'])):?><span class="tag risk-<?=h((string)$c['risk_class'])?>"><?=h(strtoupper((string)$c['risk_class']))?></span><?php endif;?><p class="muted">ID <code><?=h((string)$c['id'])?></code><br>Genome <code><?=h((string)($c['genome_id']??''))?></code><br>Parent <code><?=h((string)($c['parent']??''))?></code><br>Fitness <?=h((string)($c['fitness']['percent']??0))?>%<br><?=h((string)($c['mutation_reason']??''))?></p><?php if(!empty($c['fitness']['passed'])&&in_array((string)($c['status']??''),['fit','awaiting_human'],true)):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="promote_evolution_candidate"><input type="hidden" name="candidate_id" value="<?=h((string)$c['id'])?>"><label><input type="checkbox" name="candidate_ack" value="1" required> Candidate manuell zur Promotion auswählen</label><br><label>Version exakt eingeben: <input name="candidate_version_confirm" placeholder="<?=h((string)$c['to_version'])?>" required></label><br><button class="approve">In Self-Update übernehmen</button></form><?php endif;?></div><?php endforeach;?></div><?php endif;?>
</section>
<section><h2>Human Authorization Bridge</h2>
<?php $tas=kicomTotpPublicStatus();$ap=kicomAutonomyPolicy();$tp=kicomTotpPending();?>
<p class="muted">Einmalige FreeOTP-Einrichtung ersetzt wiederkehrende Admin-Klicks. Ein TOTP-Code gilt nur im aktuellen 30-Sekunden-Schritt und wird nach Verwendung als verbraucht markiert. Autonomie-Sitzungen verwenden danach ein rollierendes Einmal-Token; jeder Request macht das vorherige Token wertlos.</p>
<div class="grid"><div class="card"><strong>FreeOTP</strong><p class="muted">Status: <?=$tas['configured']?'aktiv':'nicht eingerichtet'?><br>Periode: 30 Sekunden · 6 Stellen · SHA-1</p>
<?php if(!$tas['configured']):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button name="action" value="totp_setup_begin">FreeOTP einmalig einrichten</button></form><?php endif;?>
<?php if(!$tas['configured']&&$tp):?><p><a href="<?=h((string)$tp['uri'])?>">In FreeOTP öffnen</a></p><p class="muted">Falls der Link nicht öffnet: Secret manuell in FreeOTP eintragen:</p><div class="secret"><?=h((string)$tp['secret'])?></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="totp_setup_confirm"><label>Aktueller FreeOTP-Code<br><input name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label><br><button class="approve">FreeOTP bestätigen</button></form><?php endif;?></div>
<div class="card"><strong>Autonomy Envelope</strong><p class="muted">Workspace, Project Memory, Goals sowie Test-/Staging-Deployments dürfen innerhalb einer FreeOTP-geöffneten Sitzung automatisch laufen. RED, Production und Kernel bleiben transaktionsgebunden an FreeOTP.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="autonomy_toggle"><label><input type="checkbox" name="autonomy_enabled" value="1" <?=!empty($ap['enabled'])?'checked':''?>> Autonomy Envelope aktiv</label><br><button>Policy speichern</button></form><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="danger" name="action" value="autonomy_revoke_sessions">Alle Sitzungen widerrufen</button></form></div></div>
<p class="warn"><strong>Grenze:</strong> Autonomie ist kein Shell-/SQL-/beliebiger-Dateisystemzugriff. Die bestehende Ziel-Allowlist, SHA-Konfliktschutz, Verifier, Backups, Healthchecks, Genome und Recovery-Grenzen bleiben aktiv.</p>
</section>
<section><h2>Updatekanäle & Self-Update</h2>
<?php $uc=kicomUpdateChannelsLoad();$us=kicomUpdateChannelsPublicStatus();$su=kicomSelfUpdatePending();$pushKey=kicomUpdatePushKey();$agentKey=kicomUpdateAgentKey();$baseUrl='https://'.(string)($_SERVER['HTTP_HOST']??'HOST').rtrim(dirname((string)($_SERVER['SCRIPT_NAME']??'/admin.php')),'/');?>
<p class="muted">Drei Wege bleiben parallel erhalten: manueller Admin-Upload, Pull über bis zu zwei HTTPS-Feeds und authentifizierter Push direkt in die Inbox. Alle drei enden im <strong>gleichen</strong> Paketprüfer, Genome-/Lineage-Check und Risikomodell.</p>
<div class="grid">
  <div class="card"><strong>Grün</strong> <span class="tag risk-green">AUTO</span><p class="muted">Nur Präsentation/Metadaten; Sicherheitsgrenzen unverändert. Darf nach vollständiger Prüfung selbst installieren.</p></div>
  <div class="card"><strong>Gelb</strong> <span class="tag risk-yellow">1 KLICK</span><p class="muted">Geprüfter Code-/Memory-Change ohne rote Grenze. KiCom bereitet alles vor; Mensch entscheidet einmal.</p></div>
  <div class="card"><strong>Rot</strong> <span class="tag risk-red">BEWUSST</span><p class="muted">Kernel, API/Perimeter, Invarianten oder andere Vertrauensgrenzen. Explizite Bestätigung bleibt Pflicht.</p></div>
</div>

<h3>Kanäle konfigurieren</h3>
<form method="post">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save_update_channels">
<p><label><input type="checkbox" name="auto_install_green" value="1" <?=!empty($uc['auto_install_green'])?'checked':''?>> Grüne Updates automatisch installieren</label></p>
<p><label><input type="checkbox" name="pull_enabled" value="1" <?=!empty($uc['pull']['enabled'])?'checked':''?>> Pull-Kanal aktiv</label></p>
<div class="grid">
<label>Primärer Feed (HTTPS)<br><input name="feed_primary_url" value="<?=h((string)($uc['pull']['feeds'][0]['url']??''))?>" placeholder="https://update.rurtalbahn.info/kicom/channel.json"><br><span class="small"><input type="checkbox" name="feed_primary_enabled" value="1" <?=!empty($uc['pull']['feeds'][0]['enabled'])?'checked':''?>> aktiv</span></label>
<label>Mirror-Feed (HTTPS)<br><input name="feed_mirror_url" value="<?=h((string)($uc['pull']['feeds'][1]['url']??''))?>" placeholder="https://mirror.example/kicom/channel.json"><br><span class="small"><input type="checkbox" name="feed_mirror_enabled" value="1" <?=!empty($uc['pull']['feeds'][1]['enabled'])?'checked':''?>> aktiv</span></label>
</div>
<p><label><input type="checkbox" name="push_enabled" value="1" <?=!empty($uc['push']['enabled'])?'checked':''?>> Push-/Inbox-Kanal aktiv</label></p>
<button>Kanäle speichern</button>
</form>

<h3>Maschinenzugänge</h3>
<div class="grid">
  <div class="card"><strong>Push-Inbox</strong><p class="muted">POST ZIP an <code><?=h($baseUrl.'/api.php?q=UPDATE_PUSH')?></code><br>Header <code>X-KiCom-Update-Key</code> oder Bearer.</p><div class="secret"><?=h($pushKey)?></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button name="action" value="rotate_push_key">Push-Schlüssel rotieren</button></form></div>
  <div class="card"><strong>Update-Agent / Cron</strong><p class="muted">Prüft Pull-Feeds auch ohne Benutzeraktivität. Grüne Releases können selbst installiert werden.</p><div class="secret"><?=h($baseUrl.'/?q=UPDATE_AGENT&key='.$agentKey)?></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button name="action" value="rotate_agent_key">Agent-Key rotieren</button></form></div>
</div>
<p class="warn"><strong>Transport ≠ Vertrauen.</strong> Push-Schlüssel bzw. HTTPS-Feed bestimmen nur, wie ein Paket ankommt. Installiert wird erst nach demselben Manifest-, SHA-, PHP-, Genome-, Lineage-, Risiko-, Backup- und Healthcheck-Pfad.</p>

<h3>Manueller Recovery-Kanal</h3>
<?php if(!kicomSelfUpdateSupported()):?><p class="err"><strong>Kein ZIP-Leser verfügbar.</strong> Self-Updates benötigen ZipArchive oder PharData.</p><?php else:?>
<?php if($su):?>
<div class="card"><strong>Bereit: KiCom <?=h((string)$su['from_version'])?> → <?=h((string)$su['to_version'])?></strong> <span class="tag risk-<?=h((string)($su['risk_class']??'red'))?>"><?=h(strtoupper((string)($su['risk_class']??'red')))?></span>
<p class="muted">Quelle <?=h((string)($su['source']??'unbekannt'))?> · Paket <?=h((string)$su['original_name'])?><br>SHA-256 <code><?=h((string)$su['zip_sha256'])?></code><br><?=h(implode(' · ',array_map('strval',$su['risk_reasons']??[])))?></p>
<div class="row">
<?php $sr=(string)($su['risk_class']??'red'); if($sr==='green'):?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="install_self_update"><button class="approve">Grünes Update installieren</button></form>
<?php elseif($sr==='yellow'):?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="install_self_update"><input type="hidden" name="yellow_ack" value="1"><button class="approve">Gelbes Update installieren</button></form>
<?php else:?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="install_self_update"><label><input type="checkbox" name="update_ack" value="1" required> Rote Änderung bewusst freigeben</label><br><label>Zielversion exakt eingeben: <input name="update_version_confirm" placeholder="<?=h((string)$su['to_version'])?>" required autocomplete="off"></label><?php if(!empty($su['kernel_update'])):?><div class="prodconfirm"><strong>Recovery-Kernel wird verändert.</strong><br><label><input type="checkbox" name="kernel_ack" value="1" required> Kernel-Update ausdrücklich freigeben</label><br><label><code>KERNEL <?=h((string)$su['to_version'])?></code> exakt eingeben: <input name="kernel_confirm" required autocomplete="off"></label></div><?php endif;?><br><button class="danger">Rotes Update installieren</button></form>
<?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="discard_self_update"><button class="danger">Paket verwerfen</button></form></div></div>
<?php else:?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="stage_self_update"><label>KiCom Update-ZIP<br><input type="file" name="update_zip" accept=".zip,application/zip" required></label><p class="muted">Fallback/Recovery: lokaler Upload bleibt erhalten, läuft aber durch exakt denselben Prüfer und dasselbe Risikomodell.</p><button>Update prüfen</button></form>
<?php endif;?>
<?php $suh=kicomSelfUpdateHistory();if($suh):?><h3>Self-Update-Historie</h3><div class="grid"><?php foreach(array_slice($suh,0,8) as $u):?><div class="card"><strong><?=h((string)($u['from_version']??'?'))?> → <?=h((string)($u['to_version']??'?'))?></strong><p class="muted"><?=h((string)($u['action']??''))?> · <?=h((string)($u['status']??''))?><br>ID <code><?=h((string)($u['id']??''))?></code></p><?php if(($u['action']??'')==='self_update'&&($u['status']??'')==='installed'&&($u['to_version']??'')===KICOM_VERSION):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="rollback_self_update"><input type="hidden" name="history_id" value="<?=h((string)$u['id'])?>"><label><input type="checkbox" name="rollback_ack" value="1" required> Rückkehr bestätigen</label><br><label>Vorherige Version: <input name="rollback_version_confirm" placeholder="<?=h((string)$u['from_version'])?>" required autocomplete="off"></label><br><button class="danger">Zurückrollen</button></form><?php endif;?></div><?php endforeach;?></div><?php endif;?>
<?php endif;?></section>
<section><h2>Deployment-Ziele</h2><p class="warn">Nur ausdrücklich hier eingetragene Ziele können beschrieben werden. Für den ersten Live-Test bitte ausschließlich ein leeres Testverzeichnis verwenden, noch kein produktives Portal.</p>
<?php $dts=kicomLoadDeployTargets();if($dts):?><div class="grid"><?php foreach($dts as $a=>$t):?><div class="card <?=$t['class']==='production'?'prodcard':''?>"><strong><?=h($a)?></strong> <span class="tag <?=$t['class']==='production'?'prodtag':''?>"><?=h((string)$t['class'])?></span><p class="muted"><?=h((string)$t['label'])?><br>Status: <?=$t['enabled']?'aktiv':'deaktiviert'?><br>Healthcheck: <?=$t['health_url']!==''?'konfiguriert':'aus'?></p><?php if($t['class']==='production'):?><p class="err">Produktionsziel: Deploy-Freigaben benötigen Alias-Bestätigung; HTTPS-Healthcheck ist Pflicht.</p><?php endif;?><form method="post" onsubmit="return confirm('Deployment-Ziel wirklich entfernen?');"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_deploy_target"><input type="hidden" name="alias" value="<?=h($a)?>"><button class="danger">Ziel entfernen</button></form></div><?php endforeach;?></div><?php else:?><p>Noch keine Deployment-Ziele konfiguriert.</p><?php endif;?>
<h3>Ziel hinzufügen / aktualisieren</h3><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save_deploy_target"><div class="grid"><label>Alias<br><input name="alias" placeholder="testportal" required pattern="[a-z0-9][a-z0-9_-]{1,31}"></label><label>Bezeichnung<br><input name="label" placeholder="Testportal"></label><label>Zielklasse<br><select name="class" required><option value="test">test</option><option value="staging">staging</option><option value="production">production</option></select></label><label>Absoluter Document-Root<br><input name="root" placeholder="/www/htdocs/.../test" required></label><label>Health-URL (HTTPS; für production Pflicht)<br><input name="health_url" placeholder="https://portal.example/health"></label></div><p><label><input type="checkbox" name="enabled" value="1"> Ziel aktivieren</label></p><p class="warn"><label><input type="checkbox" name="production_target_ack" value="1"> Nur falls Klasse <strong>production</strong>: Ich bestätige, dass dies ein Produktionsziel ist und der HTTPS-Healthcheck korrekt eingerichtet wurde.</label></p><button>Deployment-Ziel speichern</button></form></section>
<section><h2>Project Memory</h2><p class="muted">Kanonischer Laufzeitstand liegt geschützt unter <code>var/</code>; die mitgelieferten <code>memory/</code>-Dateien dienen nach Initialisierung nur als Seed.</p><div class="grid"><?php foreach(kicomListMemoryResources() as $m):?><div class="card"><strong><?=h($m['resource'])?></strong><p class="muted"><?=h((string)$m['bytes'])?> Bytes<br>Revisionen: <?=h((string)$m['revisions'])?><br><code><?=h($m['sha256'])?></code></p></div><?php endforeach;?></div></section>
<section><h2>Workspace-Dateien</h2><?php $fs=kicomListStageFiles();if(!$fs):?><p>Workspace ist leer.</p><?php else:?><div class="grid"><?php foreach($fs as $f):?><div class="card"><strong><?=h($f['path'])?></strong><p class="muted"><?=h((string)$f['bytes'])?> Bytes<br>Revisionen: <?=h((string)$f['revisions'])?><br><code><?=h($f['sha256'])?></code></p><form method="post" onsubmit="return confirm('Datei aus dem Workspace löschen? Der Verlauf bleibt erhalten.');"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete_workspace"><input type="hidden" name="path" value="<?=h($f['path'])?>"><button class="danger">Workspace-Datei löschen</button></form></div><?php endforeach;?></div><?php endif;?></section>
<section><h2>Deployment-Historie</h2><?php $dh=kicomDeployHistoryFiles();if(!$dh):?><p>Noch keine Deployments.</p><?php else:?><div class="grid"><?php foreach(array_slice($dh,0,20) as $d):?><?php $isPkgHist=str_starts_with((string)($d['action']??''),'package_');?><div class="card"><strong><?=h((string)($d['target']??''))?> / <?=h($isPkgHist?(string)($d['package_name']??'package'):(string)($d['dest_path']??''))?></strong> <span class="tag"><?=h((string)($d['target_class']??'test'))?></span><p class="muted">ID <code><?=h((string)($d['id']??''))?></code><br><?=h((string)($d['action']??''))?> · <?=h((string)($d['status']??''))?><?php if($isPkgHist):?><br>Dateien: <?=h((string)($d['files_count']??0))?> · Manifest <code><?=h((string)($d['manifest_sha256']??''))?></code><?php else:?><br>vorher <code><?=h((string)($d['before_sha256']??''))?></code><br>nachher <code><?=h((string)($d['after_sha256']??''))?></code><?php endif;?></p></div><?php endforeach;?></div><?php endif;?></section>
<section><h2>Sicherheitsmodell 0.9.6</h2><ul><li>Human Control Plane: Aufmerksamkeit zuerst; technische Details standardmäßig eingeklappt</li><li>Redundante Updatewege: Admin-Upload, HTTPS-Pull (Primär/Mirror) und authentifizierter Push laufen durch denselben Verifikationskern</li><li>Risikoklassen: Grün automatisch, Gelb ein bewusster Klick, Rot explizite Bestätigung</li><li>Living Architecture: Genome (Sollzustand), Memory (Erfahrung) und Phenotype (laufender Zustand) sind getrennt; bekannte Abweichungen werden autonom ausschließlich zurück zum bereits vertrauten Zustand geheilt</li><li>Autonomous Immune Guardian: Core-Requests lösen gedrosselte Integritätsprüfungen aus; URL-Cron deckt Leerlaufzeiten ab; Healing-Lock verhindert Parallelreparaturen</li><li>Maintenance-Lock pausiert die Immunreaktion während legitimer Self-Updates und Rollbacks</li><li>Recovery Kernel ist separater Root-of-Trust; Kernel-Abweichungen werden nicht automatisch geheilt</li><li>Autonomous Evolution: Insights → Ziele → Candidate-Fitness → grüne Auto-Promotion; Gelb/Rot bleiben human-gated. Jede 10. erfolgreiche Evolutionsgeneration erzeugt einen Epoch-Bericht und einen LKG-Metadatensnapshot.</li><li>Self-Update: manifestierte Admin-ZIP-Pakete, vollständiges Backup aller betroffenen Dateien, Integritätsprüfung, Post-Update-Healthcheck und automatischer Rollback; Laufzeitdaten in <code>var/</code>/<code>stage/</code> bleiben erhalten, nur ihre Deny-Sentinels sind Genome-Komponenten</li><li>Öffentliche Root-URL liefert absichtlich 404; alle PHP-Antworten tragen <code>X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex</code>; <code>robots.txt</code> sperrt Crawler</li><li>Zielklassen: <strong>test / staging / production</strong></li><li>Produktionsziele benötigen zwingend einen HTTPS-Healthcheck vor und nach dem Schreiben sowie Alias-Bestätigung</li><li>Deployment: <strong>nur allowlist-basiert und human-gated</strong></li><li>Pakete verwenden ausschließlich explizite JSON-Manifeste aus <code>packages/*.json</code>; maximal 20 Dateien und 1 MiB Nutzdaten</li><li>Vor Paketfreigabe werden Manifest, alle Workspace-Hashes, alle Zielbasen, Validierungen, Schreibrechte und Backup-Grenzen geprüft</li><li>Alle Paketdateien werden vor dem Commit vorbereitet und gesichert; Commit- oder Healthcheck-Fehler lösen eine sofortige Wiederherstellung des kompletten Backup-Sets aus</li><li>Paket-Rollbacks sind ebenfalls human-gated und prüfen alle aktuellen Ziel-Hashes vor der ersten Änderung</li><li>Mutationen: POST-Body bevorzugt; GET nur über kurzlebigen Einmal-Intent</li><li>Workspace und Project Memory: nicht direkt per Web ausführbar</li><li>SHA-256-Basisschutz verhindert veraltete Überschreibungen</li><li>Memory kann die tatsächliche Runtime-Policy nicht erweitern</li></ul></section>
</div></details><main>
<?php endif;?></main></body></html>
