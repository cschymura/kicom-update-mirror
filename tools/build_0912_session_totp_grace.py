#!/usr/bin/env python3
import hashlib,json,shutil,subprocess,sys,tempfile,zipfile,importlib.util
from pathlib import Path
from datetime import datetime,timezone

BASE_SHA='607b3f559269aae09f7aea3c39c2338ca773045121a4de1e5417590dda23e5f9'
BASE='0.9.11'; VER='0.9.12'; GID='kicom-0.9.12-g13'; PARENT='kicom-0.9.11-g12'; GEN=13
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()
def run(c):
    print('+',' '.join(map(str,c)))
    return subprocess.run(c,text=True,capture_output=True,check=True)

def main():
    if len(sys.argv)!=3: raise SystemExit('usage: build_0912_session_totp_grace.py SOURCE_0911_DIR OUT_ZIP')
    b=Path(sys.argv[1]).resolve(); o=Path(sys.argv[2]).resolve()
    s=json.loads((b/'SOURCE-SNAPSHOT.json').read_text())
    assert s['version']==BASE and s['release_sha256']==BASE_SHA
    for l in (b/'MANIFEST.sha256').read_text().splitlines():
        if l.strip():
            h,p=l.split('  ',1); assert sha(b/p)==h,p
    spec=importlib.util.spec_from_file_location('h',Path('tools/build_0910_transaction_buffer.py'))
    h=importlib.util.module_from_spec(spec); spec.loader.exec_module(h)
    r=Path(tempfile.mkdtemp(prefix='k0912-'))
    try:
        for x in b.iterdir():
            if x.name=='SOURCE-SNAPSHOT.json': continue
            shutil.copytree(x,r/x.name) if x.is_dir() else shutil.copy2(x,r/x.name)

        lp=r/'lib.php'; x=lp.read_text(); x=h.replace_once(x,"const KICOM_VERSION = '0.9.11';","const KICOM_VERSION = '0.9.12';",'version'); lp.write_text(x)

        vp=r/'living.php'; x=vp.read_text(); x=h.replace_once(x,'/* KiCom Living Architecture 0.9.11 */','/* KiCom Living Architecture 0.9.12 */','comment')
        x=h.replace_function(x,'kicomTotpVerifyConsume',r'''function kicomTotpVerifyConsumeWindow(string $code,string $purpose,int $pastSteps=0): array {
    $cfg=kicomTotpConfig();if($cfg===null)return ['ok'=>false,'code'=>'TOTP_NOT_CONFIGURED'];if(!kicomTotpRateAllowed(false))return ['ok'=>false,'code'=>'TOTP_RATE_LIMIT'];
    $code=preg_replace('/\\D/','',$code)??'';if(strlen($code)!==6)return ['ok'=>false,'code'=>'TOTP_CODE_INVALID'];$period=max(1,(int)($cfg['period']??30));$current=intdiv(time(),$period);$pastSteps=max(0,min(4,$pastSteps));$last=(int)($cfg['last_counter']??-1);$matched=null;
    for($age=0;$age<=$pastSteps;$age++){$counter=$current-$age;if($counter<0)continue;$expected=kicomTotpCodeForCounter((string)$cfg['secret'],$counter,(int)($cfg['digits']??6));if($expected!==null&&hash_equals($expected,$code)){if($counter<=$last)return ['ok'=>false,'code'=>'TOTP_REPLAY'];$matched=$counter;break;}}
    if($matched===null)return ['ok'=>false,'code'=>'TOTP_CODE_REJECTED'];$cfg['last_counter']=$matched;$cfg['last_used_at']=gmdate('c');$cfg['last_purpose']=substr($purpose,0,120);
    if(!kicomAuthJsonWrite(kicomTotpFile(),$cfg))return ['ok'=>false,'code'=>'TOTP_STATE_WRITE_FAILED'];kicomTotpRateAllowed(true);return ['ok'=>true,'counter'=>$matched,'age_steps'=>$current-$matched,'period'=>$period];
}
function kicomTotpVerifyConsume(string $code,string $purpose): array {return kicomTotpVerifyConsumeWindow($code,$purpose,0);}''')
        x=h.replace_function(x,'kicomAutonomyPolicy',r'''function kicomAutonomyPolicy(): array {
    $defaults=['schema'=>1,'enabled'=>true,'session_ttl'=>28800,'idle_ttl'=>1800,'session_totp_past_steps'=>2,'auto_workspace'=>true,'auto_test'=>true,'auto_staging'=>true,'auto_memory'=>true,'auto_goal'=>true,'auto_memory_archive'=>true,'auto_yellow_from_session'=>true,'red_requires_totp'=>true,'production_requires_totp'=>true,'kernel_requires_totp'=>true,'archive_append_only'=>true];
    $r=kicomAuthJsonRead(kicomAutonomyPolicyFile());if(!is_array($r)){$r=$defaults;kicomAuthJsonWrite(kicomAutonomyPolicyFile(),$r);}$r=array_replace($defaults,$r);$r['session_totp_past_steps']=max(0,min(4,(int)$r['session_totp_past_steps']));return $r;
}''')
        x=h.replace_function(x,'kicomAutonomySessionOpen',r'''function kicomAutonomySessionOpen(string $code): array {
    $policy=kicomAutonomyPolicy();if(empty($policy['enabled']))return ['ok'=>false,'code'=>'AUTONOMY_DISABLED'];$grace=max(0,min(4,(int)($policy['session_totp_past_steps']??2)));$v=kicomTotpVerifyConsumeWindow($code,'autonomy_session_open',$grace);if(!$v['ok'])return $v;
    $id=substr(kicomAuthRandomHex(12),0,24);$token=kicomAuthRandomHex(32);$now=time();$row=['schema'=>1,'id'=>$id,'token_hash'=>hash('sha256',$token),'created_at'=>gmdate('c'),'created_epoch'=>$now,'last_used_at'=>gmdate('c'),'absolute_expires_at'=>$now+(int)$policy['session_ttl'],'idle_expires_at'=>$now+(int)$policy['idle_ttl'],'scope'=>'autonomy'];
    if(!kicomAuthJsonWrite(kicomAutonomySessionFile($id),$row))return ['ok'=>false,'code'=>'SESSION_WRITE_FAILED'];kicomLivingEvent('autonomy_session_opened','info',['session_id'=>$id,'totp_age_steps'=>(int)($v['age_steps']??0)]);return ['ok'=>true,'session_id'=>$id,'token'=>$token,'expires_in'=>(int)$policy['session_ttl'],'idle_expires_in'=>(int)$policy['idle_ttl'],'totp_age_steps'=>(int)($v['age_steps']??0),'totp_period'=>(int)($v['period']??30)];
}''')
        vp.write_text(x)

        ip=r/'index.php'; x=ip.read_text()
        x=h.replace_once(x,'CAPABILITY HUMAN_AUTH freeotp_totp30 rolling_one_time_session transaction_bound_critical_approval','CAPABILITY HUMAN_AUTH freeotp_totp30 rolling_one_time_session session_open_grace_configured_past_steps transaction_bound_critical_current_step_approval','human auth cap')
        x=h.replace_once(x,"'RULE autonomy-session-open-requires-current-freeotp-code'","'RULE autonomy-session-open-accepts-current-or-configured-past-freeotp-counters-once','RULE critical-freeotp-execution-requires-current-counter'",'auth rules')
        x=h.replace_once(x,"'FACT active_sessions='.(int)$a['sessions'],'RULE totp-secret-never-public','RULE totp-code-one-use-current-30-second-step','END'","'FACT active_sessions='.(int)$a['sessions'],'FACT session_open_past_steps='.(int)($a['policy']['session_totp_past_steps']??0),'RULE totp-secret-never-public','RULE session-open-may-accept-configured-past-counters-once','RULE critical-actions-current-counter-only','END'",'auth status')
        x=h.replace_once(x,"'FACT idle_ttl='.(int)$a['policy']['idle_ttl'],'FACT auto_workspace='","'FACT idle_ttl='.(int)$a['policy']['idle_ttl'],'FACT session_totp_past_steps='.(int)($a['policy']['session_totp_past_steps']??0),'FACT auto_workspace='",'autonomy status')
        ip.write_text(x)

        ps=r/'memory/project_state.kcl'; z=ps.read_text().replace('VERSION "0.9.11"','VERSION "0.9.12"',1); z=h.replace_once(z,'END_PROJECT kicom','FACT session_open_totp_grace="current plus previous 2 FreeOTP counters by default; one-use replay protection; critical actions current-counter only"\nMILESTONE "Session TOTP grace" status=implemented version="0.9.12"\nEND_PROJECT kicom','seed project'); ps.write_text(z)
        p=r/'memory/architecture.kcl'; z=p.read_text(); z=h.replace_once(z,'END_ARCHITECTURE kicom','COMPONENT session_totp_grace role="normal autonomy-session opening may accept configured recent past counters; critical approval verification remains current-counter only"\nEND_ARCHITECTURE kicom','seed architecture'); p.write_text(z)
        p=r/'memory/protocol.kcl'; z=p.read_text(); z=h.replace_once(z,'END_PROTOCOL KCL/1','AUTH session_open_grace="default past_steps=2; current plus previous two TOTP counters accepted once for normal autonomy session opening only"\nRULE "Critical RED, production and kernel TOTP verification remains current-counter only."\nEND_PROTOCOL KCL/1','seed protocol'); p.write_text(z)
        p=r/'memory/decisions.kcl'; z=p.read_text(); z=h.replace_once(z,'END_DECISIONS kicom','DECISION D018 status=accepted title="Use bounded past-counter grace only for normal autonomy-session opening"\nRATIONALE D018 "Chat and transport latency can exceed one 30-second step; accepting the previous two counters once for session opening removes timing races while critical trust-boundary approval stays current-counter only"\nEND_DECISIONS kicom','seed decisions'); p.write_text(z)
        p=r/'memory/changelog.kcl'; z=p.read_text(); z=h.replace_once(z,'END_CHANGELOG kicom','RELEASE "0.9.12" date="2026-09-15" change="Session TOTP grace: normal autonomy-session opening accepts current plus configured recent past counters once; critical RED/production/kernel TOTP remains current-counter only"\nEND_CHANGELOG kicom','seed changelog'); p.write_text(z)
        p=r/'memory/next.kcl'; z=p.read_text(); z=h.replace_once(z,'END_NEXT kicom','PRIORITY 1 goal="After Session TOTP Grace is live and canonical memory is synchronized, evaluate alternative transport paths and compression strategies with the human"\nEND_NEXT kicom','seed next'); p.write_text(z)

        gp=r/'genome/genome.json'; g=json.loads(gp.read_text()); assert g['id']==PARENT and g['version']==BASE and int(g['generation'])==12
        g.update(id=GID,version=VER,parent=PARENT,generation=GEN,created_at=datetime.now(timezone.utc).isoformat(),mutation_reason='Bounded session-open TOTP grace for chat latency; critical RED, production and kernel verification remains current-counter only.')
        for c in g['components']: c['sha256']=sha(r/c['path'])
        gp.write_text(json.dumps(g,indent=2,ensure_ascii=False)+'\n')
        paths=sorted(set([c['path'] for c in g['components']]+['genome/genome.json','memory/project_state.kcl','memory/architecture.kcl','memory/protocol.kcl','memory/decisions.kcl','memory/changelog.kcl','memory/next.kcl']))
        (r/'MANIFEST.sha256').write_text(''.join(f'{sha(r/p)}  {p}\n' for p in paths))
        o.parent.mkdir(parents=True,exist_ok=True); o.unlink(missing_ok=True)
        with zipfile.ZipFile(o,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as zz:
            for p in sorted(paths+['MANIFEST.sha256']): zz.write(r/p,p)
        print('PACKAGE_SHA256='+sha(o))

        code=f'''require {str(b/'lib.php')!r};$x=kicomSelfUpdateZipInspect({str(o)!r});if(empty($x["ok"])){{echo "INSPECT=".($x["code"]??"?")."\\n";exit(31);}}$q=kicomSelfUpdateRiskClass($x);echo "RISK=".($q["class"]??"?")."\\nVERSION=".($x["version"]??"?")."\\n";if(($x["version"]??"")!=="0.9.12"||($x["genome"]["id"]??"")!=="kicom-0.9.12-g13")exit(32);'''
        q=run(['php','-r',code]); print(q.stdout,end='')
        for pp in sorted(r.rglob('*.php')):
            q=run(['php','-l',str(pp)]); print(q.stdout,end='')

        fr=Path(tempfile.mkdtemp(prefix='k0912fresh-'))
        try:
            with zipfile.ZipFile(o) as zz: zz.extractall(fr)
            t=Path('/tmp/test0912.php')
            t.write_text(f'''<?php
chdir({str(fr)!r});require {str(fr/'lib.php')!r};if(KICOM_VERSION!=="0.9.12")exit(41);if(!kicomEnsureStorage())exit(42);
$u=kicomTotpSetupBegin('CI');$ct=intdiv(time(),30);$cur=kicomTotpCodeForCounter($u['secret'],$ct,6);if(empty(kicomTotpSetupConfirm($cur)['ok']))exit(43);
$cfg=kicomAuthJsonRead(kicomTotpFile());$cfg['last_counter']=$ct-4;kicomAuthJsonWrite(kicomTotpFile(),$cfg);$old2=kicomTotpCodeForCounter($u['secret'],$ct-2,6);$s=kicomAutonomySessionOpen($old2);if(empty($s['ok'])||($s['totp_age_steps']??-1)!==2){{var_dump($s);exit(44);}}
$rep=kicomAutonomySessionOpen($old2);if(!empty($rep['ok'])||($rep['code']??'')!=='TOTP_REPLAY'){{var_dump($rep);exit(45);}}
$cfg=kicomAuthJsonRead(kicomTotpFile());$cfg['last_counter']=$ct-4;kicomAuthJsonWrite(kicomTotpFile(),$cfg);$critOld=kicomTotpVerifyConsume($old2,'critical_test');if(!empty($critOld['ok'])||($critOld['code']??'')!=='TOTP_CODE_REJECTED'){{var_dump($critOld);exit(46);}}$critCur=kicomTotpVerifyConsume($cur,'critical_test');if(empty($critCur['ok'])||($critCur['age_steps']??-1)!==0){{var_dump($critCur);exit(47);}}
$cfg=kicomAuthJsonRead(kicomTotpFile());$cfg['last_counter']=$ct-5;kicomAuthJsonWrite(kicomTotpFile(),$cfg);$old3=kicomTotpCodeForCounter($u['secret'],$ct-3,6);$tooOld=kicomAutonomySessionOpen($old3);if(!empty($tooOld['ok'])){{var_dump($tooOld);exit(48);}}
$p=kicomAutonomyPolicy();if((int)($p['session_totp_past_steps']??-1)!==2)exit(49);echo "SESSION_TOTP_GRACE_OK\\n";
''')
            q=run(['php',str(t)]); print(q.stdout,end='')
        finally: shutil.rmtree(fr,ignore_errors=True)
    finally: shutil.rmtree(r,ignore_errors=True)

if __name__=='__main__': main()
