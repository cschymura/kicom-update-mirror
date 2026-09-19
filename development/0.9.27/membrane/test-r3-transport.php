<?php
declare(strict_types=1);
/**
 * R3 transport regression inventory; run only against the isolated, immutable
 * 0.9.26-R3 source clone. The membrane is NOT inserted into these endpoints.
 * Exact source hashes and audited route names protect legacy semantics.
 */
$root=realpath($argv[1]??'');
$expected=realpath(sys_get_temp_dir().'/kicom-pam-r3-runtime');
if($root===false||$root!==$expected)throw new RuntimeException('Only isolated R3 clone permitted');
$manifest=file($root.'/MANIFEST.sha256',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($manifest))throw new RuntimeException('R3 manifest unavailable');
$hashes=[];
foreach($manifest as $line) {
    if(preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9._\/-]+)$/D',$line,$m))$hashes[$m[2]]=$m[1];
}
foreach(['index.php','api.php','lib.php','guardian.php','recovery.php'] as $name) {
    if(!isset($hashes[$name])||hash_file('sha256',$root.'/'.$name)!==$hashes[$name])
        throw new RuntimeException('Source identity mismatch: '.$name);
}
$index=(string)file_get_contents($root.'/index.php');
$api=(string)file_get_contents($root.'/api.php');
$checks=0;
function contractOk(bool $ok,string $name):void {
    global $checks;
    ++$checks;
    if(!$ok)throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name."\n";
}
contractOk((bool)preg_match("/case 'PING':.*?case 'HELLO':/s",$index),
    'Existing KCL liveness and bootstrap communication routes retained');
contractOk((bool)preg_match("/case 'ECHO':.*?case 'CHECK':/s",$index),
    'Existing KCL opaque echo and system check routes retained');
foreach(['SLACK_EVENTS','SLACK_STATUS','SLACK_SEND','SLACK_RECENT',
          'MAIL_STATUS','MAIL_SEND','MAIL_INBOX_STATUS','MAIL_INBOX_POLL',
          'AUTONOMY_COMPACT_BATCH','AUTONOMY_TX_BEGIN','AUTONOMY_TX_COMMIT',
          'DEV_BRIDGE','AUTH_STATUS','CHAT_UPDATE_STATUS','UPDATE_STATUS'] as $route) {
    $exists=$route==='DEV_BRIDGE'
        ? str_contains($index,"==='DEV_BRIDGE'")
        : str_contains($index,"case '".$route."':");
    contractOk($exists, 'Existing communication entry '.$route.' unchanged');
}
contractOk(str_contains($api,"if(\$_SERVER['REQUEST_METHOD']!=='POST')")
    && str_contains($api,"apiOut([KCL_PROTOCOL,'ERROR method'"),
    'POST API retains original method guard and status body');
contractOk(str_contains($api,"['DEV_AUTH','DEV_API']")
    && str_contains($api,"kicomDevApiDispatch(\$_SERVER,\$raw)"),
    'DEV JSON authentication and dispatch remain original');
contractOk(str_contains($api,"['AUTONOMY_UPDATE_UPLOAD','CHAT_UPDATE_RAW']")
    && str_contains($api,"'UPDATE_PUSH'"),
    'Existing raw update and authenticated push channels remain original');
contractOk(str_contains($index,"if(!isset(\$_GET['q'])")
    && str_contains($index,"switch(\$q)"),
    'Existing KCL query dispatch is not replaced with membrane routing');
contractOk(str_contains($index,"kicomAutonomySessionConsume(")
    && str_contains($api,"kicomAutonomySessionConsume("),
    'Original rotating session checks retained on KCL and POST transports');
contractOk(str_contains($index,"case 'BRIDGE_INTENT':")
    && str_contains($index,"requireGetMutationIntent("),
    'Original intentional GET mutation guard remains in place');
contractOk(str_contains($index,"case 'MAIL_RECIPIENT_ALLOW_PREPARE':")
    && str_contains($index,"case 'AUTH_APPROVAL_EXECUTE':"),
    'External-recipient and protected-action authorization routes remain separate');
echo "MEMBRANE_TRANSPORT_CONTRACT_TESTS_PASSED=$checks\n";
