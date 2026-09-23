<?php
declare(strict_types=1);
/**
 * DEV-95: first-party HTTP guard/pending challenge contract test.
 * Uses an EXPLICIT fake Passkey verifier and schema migrator: it tests the
 * original session/CSRF/one-shot HTTP wiring but is NOT WebAuthn proof.
 */
$n=0;
function ok95h(bool $v,string $label):void{
  global $n;if(!$v)throw new RuntimeException('FAIL '.$label);
  ++$n;echo "PASS ".$label."\n";
}
$dir=sys_get_temp_dir().'/dev95-http-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
register_shutdown_function(static function()use($dir):void {
  foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
  @rmdir($dir);
});
copy(__DIR__.'/KiComEngramActiveUpgradeAdminHttp.php',$dir.'/KiComEngramActiveUpgradeAdminHttp.php');
file_put_contents($dir.'/KiComEngramActiveSchemaUpgrade.php',<<<'PHP'
<?php
class KiComEngramActiveSchemaUpgrade {
    public static int $preflights=0;
    public static int $migrations=0;
    public static function preflight(string $root,array $runtime):array {
        self::$preflights++;
        if(($runtime['test_trusted']??false)!==true)throw new RuntimeException('bad runtime');
        return ['ok'=>true];
    }
    public static function applyAfterVerifiedPasskey(string $root,array $runtime):array {
        self::$migrations++;
        return ['ok'=>true,'code'=>'PRIVATE_SCHEMAS_PREPARED',
            'oauth_backup_created'=>true,'engram_backup_created'=>true,
            'write_scope_activated'=>false];
    }
}
PHP);
file_put_contents($dir.'/KiComEngramPrivateOwnerRegistry.php',<<<'PHP'
<?php
class KiComEngramPrivateOwnerRegistry {
    public static bool $enabled=true;
    public function __construct(string $registry,string $root){}
    public function __invoke(string $fp):?array {
        return ['enabled'=>self::$enabled,'subject'=>'mirage-owner',
            'credential_fingerprint'=>$fp,'namespaces'=>['project'],
            'engram_rights'=>['engram.read']];
    }
}
PHP);
file_put_contents($dir.'/KiComEngramOAuthTransactions.php',"<?php class KiComEngramOAuthTransactions{}\n");
file_put_contents($dir.'/KiComEngramMutationSchema.php',"<?php class KiComEngramMutationSchema{}\n");
class KiComPasskeyBridge {
  public static bool $verify=true;
  public static function b64uEncode(string $s):string{
    return rtrim(strtr(base64_encode($s),'+/','-_'),'=');
  }
  public function ready():array {return ['ok'=>true];}
  public function createAuthChallenge(string $public):array {
    return ['ok'=>true,'challenge_id'=>'synthetic-challenge-01'];
  }
  public function assertionOptions(string $id):array {
    return ['ok'=>true,'publicKey'=>[
      'challenge'=>'c3ludGhldGlj','userVerification'=>'required',
      'allowCredentials'=>[['id'=>'c3ludGhldGlj','type'=>'public-key']]
    ]];
  }
  public function verifyAssertion(string $challenge,array $assertion):array {
    return self::$verify && ($assertion['fake_test_only']??false)===true
      ? ['ok'=>true,'credential_id'=>'synthetic-passkey-credential']
      : ['ok'=>false];
  }
}
require $dir.'/KiComEngramActiveUpgradeAdminHttp.php';
$csrf=str_repeat('c',48);$sessionId=str_repeat('s',40);
$session=['admin'=>true,'csrf'=>$csrf];$runtime=[
 'test_trusted'=>true,
 'owner_binding'=>hash('sha256',"mirage-owner\0".hash('sha256','synthetic-passkey-credential'))
];
$server=['HTTPS'=>'on','HTTP_HOST'=>'kicom.rurtalbahn.info','REQUEST_METHOD'=>'GET'];
$passkey=new KiComPasskeyBridge();
$invoke=static function(array $server,string $raw,array &$session,array $runtime,int $now)
    use($sessionId,$csrf,$passkey):array {
    return KiComEngramActiveUpgradeAdminHttp::handle(
        $server,$raw,$session,$sessionId,$csrf,$GLOBALS['dir'],$runtime,$passkey,$now
    );
};
$time=1790130000;
$guest=$session;unset($guest['admin']);
ok95h($invoke($server,'',$guest,$runtime,$time)['http_status']===404,'guest cannot access admin upgrade');
ok95h(KiComEngramActiveSchemaUpgrade::$migrations===0,'guest does not migrate private DB');
$foreign=$server;$foreign['HTTP_ORIGIN']='https://attacker.invalid';
ok95h($invoke($foreign,'',$session,$runtime,$time)['http_status']===404,'foreign Origin is denied');
$r=$invoke($server,'',$session,$runtime,$time);
ok95h($r['http_status']===200&&str_contains($r['body'],'>FREIGABE</button>'),'one-button short FREIGABE admin page');
ok95h(KiComEngramActiveSchemaUpgrade::$migrations===0,'GET performs no schema mutation');
$post=$server;$post['REQUEST_METHOD']='POST';$post['CONTENT_TYPE']='application/json';
$bad=json_encode(['step'=>'begin','csrf'=>'invalid'],JSON_THROW_ON_ERROR);
ok95h($invoke($post,$bad,$session,$runtime,$time)['http_status']===403,'CSRF mismatch denied');
$begin=json_encode(['step'=>'begin','csrf'=>$csrf],JSON_THROW_ON_ERROR);
$started=$invoke($post,$begin,$session,$runtime,$time);
ok95h($started['http_status']===200&&isset($session['mirage_active_upgrade_pending']),'begin stores session-bound short-lived Passkey challenge');
ok95h(KiComEngramActiveSchemaUpgrade::$migrations===0,'begin is strictly read-only');
$confirm=json_encode(['step'=>'confirm','csrf'=>$csrf,
 'challenge_id'=>'synthetic-challenge-01','confirmation'=>'FREIGABE',
 'assertion'=>['fake_test_only'=>true]],JSON_THROW_ON_ERROR);
KiComPasskeyBridge::$verify=false;
ok95h($invoke($post,$confirm,$session,$runtime,$time+1)['http_status']===403,'failed signed-passkey simulation cannot migrate');
ok95h(KiComEngramActiveSchemaUpgrade::$migrations===0,'invalid proof leaves private DB unchanged');
KiComPasskeyBridge::$verify=true;
ok95h($invoke($post,$confirm,$session,$runtime,$time+1)['http_status']===403,'consumed challenge cannot be reused after failed proof');
$invoke($post,$begin,$session,$runtime,$time+2);
$altered=$runtime;$altered['nonce']='changed';
ok95h($invoke($post,$confirm,$session,$altered,$time+3)['http_status']===403,'changed trusted runtime is denied');
$invoke($post,$begin,$session,$runtime,$time+4);
KiComEngramPrivateOwnerRegistry::$enabled=false;
ok95h($invoke($post,$confirm,$session,$runtime,$time+5)['http_status']===403,'disabled original owner denied');
KiComEngramPrivateOwnerRegistry::$enabled=true;
$invoke($post,$begin,$session,$runtime,$time+6);
$r=$invoke($post,$confirm,$session,$runtime,$time+7);
ok95h($r['http_status']===200
   &&str_contains($r['body'],'PRIVATE_SCHEMAS_PREPARED')
   &&KiComEngramActiveSchemaUpgrade::$migrations===1,'fresh original-owner passkey simulation allows ONE operator migration');
ok95h($invoke($post,$confirm,$session,$runtime,$time+8)['http_status']===403,'completed confirmation cannot replay');
ok95h(KiComEngramActiveSchemaUpgrade::$migrations===1,'replayed confirmation did not migrate again');
$invoke($post,$begin,$session,$runtime,$time+9);
ok95h($invoke($post,$confirm,$session,$runtime,$time+130)['http_status']===403,'expired passkey session denies migration');
echo "DEV95_ADMIN_HTTP_ASSERTIONS=$n\n";
