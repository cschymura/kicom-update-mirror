<?php
declare(strict_types=1);
/** Test-only provenance contract; never a real consent receipt or live memory. */
require __DIR__.'/../dev88/KiComEngramVerifiedWriteProvenance.php';
$n=0;
function yes(bool $x,string $m):void{global $n;if(!$x)throw new RuntimeException("FAIL ".$m);$n++;echo "PASS ".$m."\n";}
function no(callable $fn,string $m):void{try{$fn();}catch(Throwable){yes(true,$m);return;}throw new RuntimeException('FAIL '.$m);}
$context=[
 'authenticated'=>true,
 'owner'=>'owner-0001','namespace'=>'project-01',
 'token_fingerprint'=>'fingerprint-01','scopes'=>['engram.write'],
 'synthetic_environment'=>true,
 'server_provenance'=>[
  'source_kind'=>'synthetic_test','source_ref'=>'dev-test:synthetic-001',
  'verified'=>true,'owner'=>'owner-0001','namespace'=>'project-01',
  'token_fingerprint'=>'fingerprint-01'
 ]
];
$got=KiComEngramVerifiedWriteProvenance::resolve($context);
yes($got===['source_kind'=>'synthetic_test','source_ref'=>'dev-test:synthetic-001'],'test-only provenance retained without false real-world label');
$missing=$context;unset($missing['server_provenance']);
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($missing),'absent server provenance denied');
$notApproved=$context;$notApproved['server_provenance']['verified']=false;
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($notApproved),'unverified provenance denied');
$fakeOwner=$context;$fakeOwner['server_provenance']['owner']='attacker';
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($fakeOwner),'owner mismatch denied');
$fakeSpace=$context;$fakeSpace['server_provenance']['namespace']='other-space';
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($fakeSpace),'namespace mismatch denied');
$fakeToken=$context;$fakeToken['server_provenance']['token_fingerprint']='other-token';
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($fakeToken),'token binding mismatch denied');
$read=$context;$read['scopes']=['engram.read'];
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($read),'read-only bearer denied');
$real=$context;unset($real['synthetic_environment']);
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($real),'synthetic provenance denied outside synthetic runtime');
$unsupported=$context;$unsupported['server_provenance']['source_kind']='arbitrary_user_text';
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($unsupported),'unsupported provenance kind denied');
$extra=$context;$extra['server_provenance']['consent']='caller-assertion';
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($extra),'caller fields cannot extend server provenance');
$unbound=$context;$unbound['server_provenance']['source_ref']='external://claim';
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($unbound),'synthetic test source must be bounded');
$consent=$context;unset($consent['synthetic_environment']);
$consent['server_provenance']['source_kind']='explicit_user';
$consent['server_provenance']['source_ref']='consent:'.str_repeat('a',64);
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($consent),'plausible receipt plus claimed verified flag is NOT sufficient');
$readOnlyPrivateLedger=static function(array $claim)use($consent):bool{
  return $claim['owner']===$consent['owner']
    && $claim['namespace']===$consent['namespace']
    && $claim['token_fingerprint']===$consent['token_fingerprint']
    && $claim['source_kind']==='explicit_user'
    && $claim['source_ref']===$consent['server_provenance']['source_ref'];
};
yes(KiComEngramVerifiedWriteProvenance::resolve($consent,$readOnlyPrivateLedger)['source_kind']==='explicit_user','trusted separately supplied private lookup accepts exact owner-bound receipt');
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($consent,static fn(array $r):bool=>false),'private ledger rejects invalid or revoked consent');
$fakeConsent=$consent;$fakeConsent['server_provenance']['source_ref']='user typed yes';
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($fakeConsent),'user supplied text cannot impersonate consent receipt');
$fakeConsent=$consent;$fakeConsent['synthetic_environment']=true;
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($fakeConsent),'real consent cannot be accepted in test runtime');
$notAuth=$context;$notAuth['authenticated']=false;
no(fn()=>KiComEngramVerifiedWriteProvenance::resolve($notAuth),'unauthenticated caller denied');
echo "DEV88_PROVENANCE_ASSERTIONS=$n\n";
