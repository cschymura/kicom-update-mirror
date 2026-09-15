<?php
declare(strict_types=1);

/* KiCom Recovery Kernel 0.9
 * Standalone root-of-trust helper. It intentionally does not require lib.php.
 */
const KICOM_RECOVERY_KERNEL_REVISION = 1;

function rkBase(): string { return __DIR__; }
function rkVar(): string { return rkBase().'/var'; }
function rkGenomeDir(): string { return rkBase().'/genome'; }
function rkGenomeFile(): string { return rkGenomeDir().'/genome.json'; }
function rkStateDir(): string { return rkVar().'/genome'; }
function rkTrustFile(): string { return rkStateDir().'/trust.json'; }
function rkLkgRoot(): string { return rkStateDir().'/lkg'; }
function rkQuarantineRoot(): string { return rkVar().'/quarantine'; }
function rkLivingDir(): string { return rkVar().'/living'; }
function rkEventsFile(): string { return rkLivingDir().'/events.jsonl'; }
function rkConfigFile(): string { return rkVar().'/config.php'; }

function rkHeaders(): void {
    if (PHP_SAPI==='cli') return;
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'; form-action 'self'");
}
function rkEnsureDirs(): bool {
    foreach ([rkVar(),rkStateDir(),rkLkgRoot(),rkQuarantineRoot(),rkLivingDir()] as $d) {
        if (!is_dir($d) && !@mkdir($d,0700,true) && !is_dir($d)) return false;
    }
    return true;
}
function rkSafeRel(string $path): ?string {
    $path=str_replace('\\','/',trim($path));$path=ltrim($path,'/');
    if($path===''||strlen($path)>220||str_contains($path,"\0"))return null;
    if(!preg_match('~^[A-Za-z0-9_./-]+$~',$path))return null;
    foreach(explode('/',$path) as $seg)if($seg===''||$seg==='.'||$seg==='..')return null;
    return $path;
}
function rkAtomicWrite(string $abs,string $content,int $mode=0644): bool {
    $dir=dirname($abs);if(!is_dir($dir)&&!@mkdir($dir,0755,true)&&!is_dir($dir))return false;
    $tmp=$abs.'.rk.'.bin2hex(random_bytes(4));
    if(@file_put_contents($tmp,$content,LOCK_EX)===false)return false;@chmod($tmp,$mode);
    if(!@rename($tmp,$abs)){@unlink($tmp);return false;}return true;
}
function rkEvent(string $type,string $severity='info',array $data=[]): void {
    if(!rkEnsureDirs())return;
    $row=['ts'=>gmdate('c'),'type'=>$type,'severity'=>$severity,'data'=>$data];
    $j=json_encode($row,JSON_UNESCAPED_SLASHES);if($j!==false)@file_put_contents(rkEventsFile(),$j."\n",FILE_APPEND|LOCK_EX);
}
function rkLoadGenome(): array {
    $f=rkGenomeFile();if(!is_file($f))return ['ok'=>false,'code'=>'GENOME_MISSING'];
    $raw=@file_get_contents($f);if($raw===false)return ['ok'=>false,'code'=>'GENOME_READ_FAILED'];
    $g=json_decode($raw,true);if(!is_array($g))return ['ok'=>false,'code'=>'GENOME_JSON_INVALID'];
    if((int)($g['schema']??0)!==1||!is_string($g['id']??null)||!is_string($g['version']??null)||!is_array($g['components']??null))return ['ok'=>false,'code'=>'GENOME_SCHEMA_INVALID'];
    if(!preg_match('/^[A-Za-z0-9._-]{3,80}$/',(string)$g['id']))return ['ok'=>false,'code'=>'GENOME_ID_INVALID'];
    $components=[];
    foreach($g['components'] as $c){
        if(!is_array($c))return ['ok'=>false,'code'=>'GENOME_COMPONENT_INVALID'];
        $p=rkSafeRel((string)($c['path']??''));$sha=strtolower((string)($c['sha256']??''));
        if($p===null||!preg_match('/^[a-f0-9]{64}$/',$sha))return ['ok'=>false,'code'=>'GENOME_COMPONENT_INVALID'];
        $components[]=['path'=>$p,'sha256'=>$sha,'auto_heal'=>(bool)($c['auto_heal']??true),'role'=>(string)($c['role']??'component')];
    }
    $g['components']=$components;$g['_raw']=$raw;$g['_sha256']=hash('sha256',$raw);return ['ok'=>true,'genome'=>$g];
}
function rkTrust(): ?array {
    if(!is_file(rkTrustFile()))return null;$x=json_decode((string)@file_get_contents(rkTrustFile()),true);return is_array($x)?$x:null;
}
function rkLkgDirFor(string $id): string { return rkLkgRoot().'/'.preg_replace('/[^A-Za-z0-9._-]/','_',$id); }
function rkLoadTrustedGenome(): array {
    $trust=rkTrust();if($trust===null)return rkLoadGenome();
    $expected=(string)($trust['genome_sha256']??'');$id=(string)($trust['genome_id']??'');
    if(!preg_match('/^[a-f0-9]{64}$/',$expected)||$id==='')return ['ok'=>false,'code'=>'TRUST_RECORD_INVALID'];
    $currentRaw=is_file(rkGenomeFile())?@file_get_contents(rkGenomeFile()):false;
    if(is_string($currentRaw)&&hash_equals($expected,hash('sha256',$currentRaw))){
        $g=rkLoadGenome();if($g['ok']){$g['source']='current';return $g;}
    }
    $backup=rkLkgDirFor($id).'/__genome.json';$raw=is_file($backup)?@file_get_contents($backup):false;
    if(!is_string($raw)||!hash_equals($expected,hash('sha256',$raw)))return ['ok'=>false,'code'=>'TRUSTED_GENOME_BACKUP_UNAVAILABLE'];
    $g=json_decode($raw,true);if(!is_array($g)||!is_array($g['components']??null))return ['ok'=>false,'code'=>'TRUSTED_GENOME_BACKUP_INVALID'];
    $components=[];foreach($g['components'] as $c){if(!is_array($c))return ['ok'=>false,'code'=>'TRUSTED_GENOME_BACKUP_INVALID'];$p=rkSafeRel((string)($c['path']??''));$sha=strtolower((string)($c['sha256']??''));if($p===null||!preg_match('/^[a-f0-9]{64}$/',$sha))return ['ok'=>false,'code'=>'TRUSTED_GENOME_BACKUP_INVALID'];$components[]=['path'=>$p,'sha256'=>$sha,'auto_heal'=>(bool)($c['auto_heal']??true),'role'=>(string)($c['role']??'component')];}
    $g['components']=$components;$g['_raw']=$raw;$g['_sha256']=$expected;return ['ok'=>true,'genome'=>$g,'source'=>'lkg'];
}
function rkUnknownExecutablePaths(array $known): array {
    $unknown=[];$base=rkBase();$baseLen=strlen(rtrim(str_replace('\\','/',$base),'/'))+1;
    $allowedControl=['.htaccess','genome/.htaccess','memory/.htaccess','stage/.htaccess','var/.htaccess'];
    $execExt=['php','phtml','phar','php3','php4','php5','php7','php8','cgi','pl','py','sh'];
    try{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);}catch(Throwable $e){return $unknown;}
    foreach($it as $fi){
        if(!($fi instanceof SplFileInfo))continue;$abs=str_replace('\\','/',$fi->getPathname());$rel=substr($abs,$baseLen);if($rel===false||$rel==='')continue;$rel=str_replace('\\','/',$rel);
        if(str_starts_with($rel,'var/')||str_starts_with($rel,'stage/'))continue;
        if(isset($known[$rel])||in_array($rel,$allowedControl,true))continue;
        if($fi->isLink()){$unknown[]=$rel;continue;}
        if(!$fi->isFile())continue;
        $baseName=strtolower(basename($rel));$ext=strtolower(pathinfo($rel,PATHINFO_EXTENSION));
        if(in_array($ext,$execExt,true)||$baseName==='.htaccess'||$baseName==='.user.ini')$unknown[]=$rel;
    }
    $unknown=array_values(array_unique($unknown));sort($unknown,SORT_STRING);return $unknown;
}
function rkScan(): array {
    rkEnsureDirs();$trust=rkTrust();$lg=$trust!==null?rkLoadTrustedGenome():rkLoadGenome();if(!$lg['ok'])return ['ok'=>false,'healthy'=>false,'code'=>$lg['code'],'drift'=>[],'unknown'=>[]];$g=$lg['genome'];
    $trusted=is_array($trust)&&hash_equals((string)($trust['genome_sha256']??''),(string)$g['_sha256'])&&hash_equals((string)($trust['genome_id']??''),(string)$g['id']);
    $drift=[];$known=[];$lkgDir=rkLkgDirFor((string)$g['id']);$lkgOk=true;
    $currentGenomeRaw=is_file(rkGenomeFile())?@file_get_contents(rkGenomeFile()):false;$currentGenomeSha=is_string($currentGenomeRaw)?hash('sha256',$currentGenomeRaw):'MISSING';
    if($trusted&&!hash_equals((string)$g['_sha256'],$currentGenomeSha))$drift[]=['path'=>'genome/genome.json','expected'=>$g['_sha256'],'actual'=>$currentGenomeSha,'auto_heal'=>true,'role'=>'genome'];
    foreach($g['components'] as $c){
        $p=$c['path'];$known[$p]=true;$abs=rkBase().'/'.$p;$actual=is_file($abs)?(hash_file('sha256',$abs)?:''):'MISSING';
        if(!hash_equals((string)$c['sha256'],$actual))$drift[]=['path'=>$p,'expected'=>$c['sha256'],'actual'=>$actual,'auto_heal'=>$c['auto_heal'],'role'=>$c['role']];
        if($c['auto_heal']){$lf=$lkgDir.'/'.$p;if(!is_file($lf)||!hash_equals((string)$c['sha256'],hash_file('sha256',$lf)?:''))$lkgOk=false;}
    }
    $unknown=rkUnknownExecutablePaths($known);
    $kernelDrift=false;foreach($drift as $d)if(!$d['auto_heal'])$kernelDrift=true;
    return ['ok'=>true,'healthy'=>$trusted&&count($drift)===0&&count($unknown)===0,'genome_id'=>$g['id'],'version'=>$g['version'],'genome_sha256'=>$g['_sha256'],'genome_source'=>$lg['source']??'current','trusted'=>$trusted,'lkg_ok'=>$lkgOk,'drift'=>$drift,'unknown'=>$unknown,'kernel_drift'=>$kernelDrift,'components'=>count($g['components'])];
}
function rkBootstrapTrust(bool $force=false): array {
    rkEnsureDirs();$lg=rkLoadGenome();if(!$lg['ok'])return $lg;$g=$lg['genome'];$cur=rkTrust();
    if($cur!==null&&!$force&&hash_equals((string)($cur['genome_sha256']??''),(string)$g['_sha256']))return ['ok'=>true,'code'=>'ALREADY_TRUSTED','genome_id'=>$g['id']];
    $bad=[];foreach($g['components'] as $c){$f=rkBase().'/'.$c['path'];$a=is_file($f)?(hash_file('sha256',$f)?:''):'MISSING';if(!hash_equals($c['sha256'],$a))$bad[]=$c['path'];}
    if($bad)return ['ok'=>false,'code'=>'BOOTSTRAP_COMPONENT_MISMATCH','paths'=>$bad];
    $lkg=rkLkgDirFor((string)$g['id']);if(!is_dir($lkg)&&!@mkdir($lkg,0700,true)&&!is_dir($lkg))return ['ok'=>false,'code'=>'LKG_CREATE_FAILED'];
    if(!rkAtomicWrite($lkg.'/__genome.json',(string)$g['_raw'],0600))return ['ok'=>false,'code'=>'LKG_GENOME_WRITE_FAILED'];
    foreach($g['components'] as $c){if(!$c['auto_heal'])continue;$src=rkBase().'/'.$c['path'];$dst=$lkg.'/'.$c['path'];$raw=@file_get_contents($src);if($raw===false||!rkAtomicWrite($dst,$raw,0600))return ['ok'=>false,'code'=>'LKG_WRITE_FAILED','path'=>$c['path']];}
    $t=['genome_id'=>$g['id'],'version'=>$g['version'],'genome_sha256'=>$g['_sha256'],'kernel_revision'=>(int)($g['kernel_revision']??1),'trusted_at'=>gmdate('c')];
    if(!rkAtomicWrite(rkTrustFile(),json_encode($t,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",0600))return ['ok'=>false,'code'=>'TRUST_WRITE_FAILED'];
    rkEvent('genome_trusted','info',['genome_id'=>$g['id'],'version'=>$g['version'],'force'=>$force]);return ['ok'=>true,'genome_id'=>$g['id'],'version'=>$g['version'],'genome_sha256'=>$g['_sha256']];
}
function rkQuarantine(string $rel,string $eventId): ?string {
    $safe=rkSafeRel($rel);if($safe===null)return null;$src=rkBase().'/'.$safe;if(!is_file($src)&&!is_link($src))return null;$dst=rkQuarantineRoot().'/'.$eventId.'/'.$safe;
    if(is_link($src)){$target=@readlink($src);$meta="SYMLINK\npath=".$safe."\ntarget=".(is_string($target)?$target:'UNKNOWN')."\n";return rkAtomicWrite($dst.'.symlink.txt',$meta,0600)?$dst.'.symlink.txt':null;}
    $raw=@file_get_contents($src);if($raw===false||!rkAtomicWrite($dst,$raw,0600))return null;return $dst;
}
function rkQuarantinePaths(array $paths,string $eventId): array {
    $moved=[];foreach($paths as $rel){if(!is_string($rel))continue;$safe=rkSafeRel($rel);if($safe===null)continue;$src=rkBase().'/'.$safe;$q=rkQuarantine($safe,$eventId);if($q!==null&&(is_file($src)||is_link($src))&&@unlink($src))$moved[]=$safe;}return $moved;
}
function rkHeal(bool $quarantineUnknown=false): array {
    $scan=rkScan();if(!$scan['ok'])return $scan;if(!$scan['trusted'])return ['ok'=>false,'code'=>'GENOME_NOT_TRUSTED','scan'=>$scan];
    if($scan['kernel_drift']){rkEvent('healing_blocked','critical',['reason'=>'kernel_drift']);return ['ok'=>false,'code'=>'KERNEL_DRIFT_REQUIRES_MANUAL_RECOVERY','scan'=>$scan];}
    $lg=rkLoadTrustedGenome();if(!$lg['ok'])return $lg;$g=$lg['genome'];$lkg=rkLkgDirFor((string)$g['id']);$eventId=gmdate('YmdHis').'-'.bin2hex(random_bytes(4));$repaired=[];$quarantined=[];
    foreach($scan['drift'] as $d){if(!$d['auto_heal'])continue;$p=$d['path'];$src=$p==='genome/genome.json'?$lkg.'/__genome.json':$lkg.'/'.$p;if(!is_file($src)||!hash_equals($d['expected'],hash_file('sha256',$src)?:''))return ['ok'=>false,'code'=>'LKG_INVALID','path'=>$p];
        if(is_file(rkBase().'/'.$p)||is_link(rkBase().'/'.$p))rkQuarantine($p,$eventId);$raw=@file_get_contents($src);if($raw===false||!rkAtomicWrite(rkBase().'/'.$p,$raw,0644))return ['ok'=>false,'code'=>'REPAIR_WRITE_FAILED','path'=>$p];$repaired[]=$p;}
    $after=rkScan();if($quarantineUnknown&&!empty($after['unknown'])){$quarantined=rkQuarantinePaths((array)$after['unknown'],$eventId);$after=rkScan();}
    $ok=$after['ok']&&!empty($after['trusted'])&&empty($after['drift'])&&empty($after['unknown']);
    rkEvent('self_heal',$ok?'info':'error',['event_id'=>$eventId,'repaired'=>$repaired,'quarantined'=>$quarantined,'unknown'=>$after['unknown']??[],'healthy'=>$after['healthy']??false]);
    return ['ok'=>$ok,'code'=>$ok?'HEALED':'HEAL_INCOMPLETE','event_id'=>$eventId,'repaired'=>$repaired,'quarantined'=>$quarantined,'scan'=>$after];
}
function rkUnknownQuarantine(): array {
    $scan=rkScan();if(!$scan['ok'])return $scan;$id=gmdate('YmdHis').'-unknown-'.bin2hex(random_bytes(3));$moved=rkQuarantinePaths((array)$scan['unknown'],$id);
    $after=rkScan();rkEvent('unknown_quarantine','warn',['event_id'=>$id,'files'=>$moved,'healthy'=>$after['healthy']??false]);return ['ok'=>true,'event_id'=>$id,'files'=>$moved,'scan'=>$after];
}

rkHeaders();
if(defined('KICOM_RECOVERY_EMBEDDED')) return;

if(PHP_SAPI==='cli'){
    $cmd=strtolower((string)($argv[1]??'scan'));$r=$cmd==='heal'?rkHeal():($cmd==='bootstrap'?rkBootstrapTrust(true):rkScan());
    echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit(($r['ok']??false)?0:1);
}

session_name('KICOM_RECOVERY');session_start();$cfg=is_file(rkConfigFile())?require rkConfigFile():null;$cfg=is_array($cfg)?$cfg:null;
if(isset($_GET['logout'])){session_destroy();header('Location: recovery.php');exit;}
$err='';$msg='';
if(empty($_SESSION['rk_admin'])&&$_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['login'])){
    if($cfg&&password_verify((string)($_POST['password']??''),(string)($cfg['password_hash']??''))){$_SESSION['rk_admin']=true;session_regenerate_id(true);}else$err='Anmeldung fehlgeschlagen.';
}
if(!empty($_SESSION['rk_admin'])&&$_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])){
    $a=(string)$_POST['action'];if($a==='scan')$msg='Scan ausgeführt.';elseif($a==='heal'){$r=rkHeal();$msg=$r['ok']?'Heilung abgeschlossen.':'Heilung nicht vollständig: '.($r['code']??'Fehler');}elseif($a==='bootstrap'){$r=rkBootstrapTrust(true);$msg=$r['ok']?'Genom als vertrauenswürdig verankert.':'Verankerung fehlgeschlagen: '.($r['code']??'Fehler');}elseif($a==='quarantine_unknown'){$r=rkUnknownQuarantine();$msg='Unbekannte Dateien isoliert: '.count($r['files']??[]);} }
$scan=rkScan();
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet"><title>KiCom Recovery Kernel</title><style>body{font:16px system-ui;max-width:900px;margin:40px auto;padding:0 16px;background:#f4f6f8;color:#18202a}.card{background:white;border:1px solid #ccd4dd;border-radius:12px;padding:18px;margin:14px 0}.ok{color:#16734a}.bad{color:#b42318}code{word-break:break-all}button,input{font:inherit;padding:9px 12px;margin:4px}</style></head><body><h1>KiCom Recovery Kernel</h1><?php if(empty($_SESSION['rk_admin'])):?><div class="card"><p>Separater Recovery-Zugang. Funktioniert unabhängig vom normalen KiCom-Core.</p><?php if($err):?><p class="bad"><?=htmlspecialchars($err)?></p><?php endif;?><form method="post"><input type="password" name="password" placeholder="Adminpasswort" required><button name="login">Anmelden</button></form></div><?php else:?><p><a href="?logout=1">Abmelden</a></p><?php if($msg):?><div class="card"><?=htmlspecialchars($msg)?></div><?php endif;?><div class="card"><strong class="<?=($scan['healthy']??false)?'ok':'bad'?>"><?=($scan['healthy']??false)?'GESUND':'ABWEICHUNG ERKANNT'?></strong><p>Genom: <?=htmlspecialchars((string)($scan['genome_id']??'?'))?> · Version <?=htmlspecialchars((string)($scan['version']??'?'))?><br>Vertrauen: <?=!empty($scan['trusted'])?'verankert':'nicht verankert'?> · LKG: <?=!empty($scan['lkg_ok'])?'OK':'unvollständig'?><br>Drift: <?=count($scan['drift']??[])?> · Unbekannt: <?=count($scan['unknown']??[])?></p></div><?php foreach($scan['drift']??[] as $d):?><div class="card bad"><strong><?=htmlspecialchars($d['path'])?></strong><br>erwartet <code><?=htmlspecialchars($d['expected'])?></code><br>ist <code><?=htmlspecialchars($d['actual'])?></code><br>Auto-Heal: <?=$d['auto_heal']?'ja':'NEIN (Kernel)'?></div><?php endforeach;?><?php foreach($scan['unknown']??[] as $u):?><div class="card"><strong>Unbekannte ausführbare Datei:</strong> <?=htmlspecialchars($u)?></div><?php endforeach;?><div class="card"><form method="post"><button name="action" value="scan">Neu scannen</button><button name="action" value="heal">Bekannte Schäden heilen</button><button name="action" value="quarantine_unknown">Unbekannte Dateien isolieren</button><button name="action" value="bootstrap" onclick="return confirm('Aktuellen fehlerfreien Zustand als vertrauenswürdiges Genom verankern?')">Genom neu verankern</button></form></div><?php endif;?></body></html>
