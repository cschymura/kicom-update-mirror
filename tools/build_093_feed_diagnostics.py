from pathlib import Path
import hashlib, json, re, shutil, sys, zipfile

if len(sys.argv) != 3:
    raise SystemExit('usage: build_093_feed_diagnostics.py <base-zip> <output-zip>')
base_zip = Path(sys.argv[1])
out_zip = Path(sys.argv[2])
work = Path('/tmp/kicom-093-feed-diagnostics')
if work.exists(): shutil.rmtree(work)
work.mkdir(parents=True)
with zipfile.ZipFile(base_zip) as zf:
    zf.extractall(work)

def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

lib = work / 'lib.php'
s = lib.read_text()
s = s.replace("const KICOM_VERSION = '0.9.2';", "const KICOM_VERSION = '0.9.3';", 1)
old = '''function kicomUpdateChannelsPublicStatus(): array {
    $cfg=kicomUpdateChannelsLoad();$state=kicomUpdateChannelState();$pending=kicomSelfUpdatePending();
    $feeds=[];foreach(($cfg['pull']['feeds']??[]) as $f)$feeds[]=[
        'name'=>(string)($f['name']??'feed'),
        'enabled'=>(bool)($f['enabled']??false),
        'configured'=>trim((string)($f['url']??''))!=='',
        'host'=>trim((string)($f['url']??''))!==''?(string)parse_url((string)$f['url'],PHP_URL_HOST):''
    ];
    return [
        'auto_install_green'=>(bool)$cfg['auto_install_green'],
        'pull_enabled'=>(bool)($cfg['pull']['enabled']??false),
        'push_enabled'=>(bool)($cfg['push']['enabled']??false),
        'feeds'=>$feeds,
        'last_check_at'=>(string)($state['last_check_at']??''),
        'last_code'=>(string)($state['last_code']??'never'),
        'last_source'=>(string)($state['last_source']??''),
        'pending_version'=>(string)($pending['to_version']??''),
        'pending_risk'=>(string)($pending['risk_class']??''),
        'pending_source'=>(string)($pending['source']??'')
    ];
}'''
new = '''function kicomUpdateChannelsPublicStatus(): array {
    $cfg=kicomUpdateChannelsLoad();$state=kicomUpdateChannelState();$pending=kicomSelfUpdatePending();
    $checks=[];
    foreach(($state['feed_checks']??[]) as $c)if(is_array($c))$checks[(string)($c['name']??'feed')]=$c;
    $feeds=[];foreach(($cfg['pull']['feeds']??[]) as $f){
        $name=(string)($f['name']??'feed');$diag=$checks[$name]??[];
        $feeds[]=[
            'name'=>$name,
            'enabled'=>(bool)($f['enabled']??false),
            'configured'=>trim((string)($f['url']??''))!=='',
            'host'=>trim((string)($f['url']??''))!==''?(string)parse_url((string)$f['url'],PHP_URL_HOST):'',
            'checked_at'=>(string)($diag['checked_at']??''),
            'ok'=>(bool)($diag['ok']??false),
            'code'=>(string)($diag['code']??'not_checked'),
            'http_code'=>(int)($diag['http_code']??0),
            'json_valid'=>(bool)($diag['json_valid']??false),
            'releases'=>(int)($diag['releases']??0)
        ];
    }
    return [
        'auto_install_green'=>(bool)$cfg['auto_install_green'],
        'pull_enabled'=>(bool)($cfg['pull']['enabled']??false),
        'push_enabled'=>(bool)($cfg['push']['enabled']??false),
        'feeds'=>$feeds,
        'last_check_at'=>(string)($state['last_check_at']??''),
        'last_code'=>(string)($state['last_code']??'never'),
        'last_source'=>(string)($state['last_source']??''),
        'pending_version'=>(string)($pending['to_version']??''),
        'pending_risk'=>(string)($pending['risk_class']??''),
        'pending_source'=>(string)($pending['source']??'')
    ];
}'''
if old not in s: raise SystemExit('public status block not found')
s = s.replace(old, new, 1)
old2 = '''    $candidates=[];$errors=[];
    foreach(($cfg['pull']['feeds']??[]) as $feed){
        if(!is_array($feed)||empty($feed['enabled']))continue;$url=trim((string)($feed['url']??''));if($url==='')continue;
        if(!kicomUpdateFeedUrlAllowed($url)){$errors[]=['feed'=>$feed['name']??'feed','code'=>'FEED_URL_NOT_ALLOWED'];continue;}
        $get=kicomUpdateHttpGet($url,KICOM_UPDATE_FEED_MAX_BYTES);
        if(!$get['ok']){$errors[]=['feed'=>$feed['name']??'feed','code'=>$get['code']??'FETCH_FAILED'];continue;}
        $parsed=kicomUpdateFeedParse((string)$get['body'],$url);
        if(!$parsed['ok']){$errors[]=['feed'=>$feed['name']??'feed','code'=>$parsed['code']??'FEED_INVALID'];continue;}
        foreach($parsed['releases'] as $rel)$candidates[]=$rel+['feed_name'=>(string)($feed['name']??'feed'),'feed_url'=>$url];
    }
    $now=['last_check_epoch'=>time(),'last_check_at'=>gmdate('c')];
    if(!$candidates){
        $now+=['last_code'=>$errors?'NO_RELEASE_FEED_ERRORS':'NO_UPDATE','last_source'=>'pull','errors'=>$errors];kicomUpdateChannelStateWrite($now);
        return ['ok'=>true,'code'=>$now['last_code'],'errors'=>$errors];
    }'''
new2 = '''    $candidates=[];$errors=[];$feedChecks=[];
    foreach(($cfg['pull']['feeds']??[]) as $feed){
        if(!is_array($feed)||empty($feed['enabled']))continue;$url=trim((string)($feed['url']??''));if($url==='')continue;
        $name=(string)($feed['name']??'feed');$diag=['name'=>$name,'checked_at'=>gmdate('c'),'ok'=>false,'code'=>'not_checked','http_code'=>0,'json_valid'=>false,'releases'=>0];
        if(!kicomUpdateFeedUrlAllowed($url)){$diag['code']='FEED_URL_NOT_ALLOWED';$feedChecks[]=$diag;$errors[]=['feed'=>$name,'code'=>$diag['code']];continue;}
        $get=kicomUpdateHttpGet($url,KICOM_UPDATE_FEED_MAX_BYTES);$diag['http_code']=(int)($get['http_code']??0);
        if(!$get['ok']){$diag['code']=(string)($get['code']??'FETCH_FAILED');$feedChecks[]=$diag;$errors[]=['feed'=>$name,'code'=>$diag['code'],'http_code'=>$diag['http_code']];continue;}
        $parsed=kicomUpdateFeedParse((string)$get['body'],$url);
        if(!$parsed['ok']){$diag['code']=(string)($parsed['code']??'FEED_INVALID');$feedChecks[]=$diag;$errors[]=['feed'=>$name,'code'=>$diag['code'],'http_code'=>$diag['http_code']];continue;}
        $diag['ok']=true;$diag['code']='OK';$diag['json_valid']=true;$diag['releases']=count($parsed['releases']);$feedChecks[]=$diag;
        foreach($parsed['releases'] as $rel)$candidates[]=$rel+['feed_name'=>$name,'feed_url'=>$url];
    }
    $now=['last_check_epoch'=>time(),'last_check_at'=>gmdate('c'),'feed_checks'=>$feedChecks];
    if(!$candidates){
        $now+=['last_code'=>$errors?'NO_RELEASE_FEED_ERRORS':'NO_UPDATE','last_source'=>'pull','errors'=>$errors];kicomUpdateChannelStateWrite($now);
        return ['ok'=>true,'code'=>$now['last_code'],'errors'=>$errors,'feed_checks'=>$feedChecks];
    }'''
if old2 not in s: raise SystemExit('pull block not found')
s = s.replace(old2, new2, 1)
lib.write_text(s)

admin = work / 'admin.php'
a = admin.read_text()
old3 = '''    <div class="channel"><strong>Pull-Kanal</strong><p class="muted">KiCom holt Releases selbst von allowlisteten HTTPS-Feeds. Primär- und Mirror-Feed können parallel konfiguriert werden.</p><div class="state"><?=!empty($cpUs['pull_enabled'])?'aktiv':'aus'?> · letzter Status <?=h((string)$cpUs['last_code'])?></div></div>'''
new3 = '''    <div class="channel"><strong>Pull-Kanal</strong><p class="muted">KiCom holt Releases selbst von allowlisteten HTTPS-Feeds. Primär- und Mirror-Feed können parallel konfiguriert werden.</p><div class="state"><?=!empty($cpUs['pull_enabled'])?'aktiv':'aus'?> · letzter Status <?=h((string)$cpUs['last_code'])?></div><?php if(!empty($cpUs['last_check_at'])):?><p class="muted">Letzte Prüfung <?=h((string)$cpUs['last_check_at'])?></p><?php endif;?><?php foreach($cpUs['feeds']??[] as $cf):?><?php if(empty($cf['enabled']))continue;$checked=(string)($cf['checked_at']??'');$code=(string)($cf['code']??'not_checked');$ok=!empty($cf['ok']);?><div class="feed-line <?=$ok?'feed-ok':'feed-bad'?>"><strong><?=h(ucfirst((string)($cf['name']??'feed')))?></strong><span><?=$ok?'✓':'✕'?></span><span><?=h((string)($cf['host']??''))?></span><span><?=($cf['http_code']??0)>0?'HTTP '.h((string)$cf['http_code']):'kein HTTP'?></span><span><?=!empty($cf['json_valid'])?'JSON gültig':'JSON nicht bestätigt'?></span><span><?=h($code)?></span><?php if($checked!==''):?><small><?=h($checked)?></small><?php endif;?></div><?php endforeach;?></div>'''
if old3 not in a: raise SystemExit('admin pull card not found')
a = a.replace(old3, new3, 1).replace('<section><h2>Sicherheitsmodell 0.9.2</h2>', '<section><h2>Sicherheitsmodell 0.9.3</h2>', 1)
admin.write_text(a)

css = work / 'assets/control-plane.css'
css.write_text(css.read_text() + "\n.feed-line{display:grid;grid-template-columns:auto auto minmax(120px,1fr) auto auto minmax(110px,1fr);gap:7px;align-items:center;margin-top:9px;padding:9px 10px;border-radius:9px;font-size:.82rem}.feed-line small{grid-column:1/-1;color:var(--muted)}.feed-ok{background:var(--goodbg);border:1px solid #b7e2ca}.feed-bad{background:var(--badbg);border:1px solid #f2b8b5}@media(max-width:720px){.feed-line{grid-template-columns:auto auto 1fr}.feed-line span:nth-of-type(n+3){grid-column:1/-1}}\n")

readme = work / 'README.md'
r = re.sub(r'^# KiCom 0\\.9\\.2.*$', '# KiCom 0.9.3 – Feed Diagnostics', readme.read_text(), count=1, flags=re.M)
r += '\n\n## 0.9.3\nPer-feed diagnostics in the Human Control Plane: last check time, HTTP status, JSON validity, release count and concrete result code. No trust-boundary or permission changes.\n'
readme.write_text(r)

gp = work / 'genome/genome.json'; g = json.loads(gp.read_text())
g.update({'id':'kicom-0.9.3-g4','version':'0.9.3','parent':'kicom-0.9.2-g3','generation':4,'created_at':'2026-09-15T02:15:00Z','mutation_reason':'Human-readable per-feed update diagnostics: HTTP status, JSON validity, result code and last-check time; no trust-boundary or permission changes.'})
for comp in g['components']:
    comp['sha256'] = sha(work / comp['path'])
gp.write_text(json.dumps(g, indent=2, ensure_ascii=False) + '\n')

for p in list((work/'var').glob('*')):
    if p.name != '.htaccess' and p.is_file(): p.unlink()
for p in list((work/'stage').glob('*')):
    if p.name != '.htaccess' and p.is_file(): p.unlink()
rows=[]
for p in sorted(work.rglob('*')):
    if not p.is_file(): continue
    rel=p.relative_to(work).as_posix()
    if rel == 'MANIFEST.sha256': continue
    rows.append(f'{sha(p)}  {rel}\n')
(work/'MANIFEST.sha256').write_text(''.join(rows))

if out_zip.exists(): out_zip.unlink()
with zipfile.ZipFile(out_zip, 'w', zipfile.ZIP_DEFLATED) as zf:
    for p in sorted(work.rglob('*')):
        if p.is_file(): zf.write(p, p.relative_to(work).as_posix())
print(sha(out_zip))
