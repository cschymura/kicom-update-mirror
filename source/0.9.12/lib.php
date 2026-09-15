<?php
declare(strict_types=1);

const KICOM_VERSION = '0.9.12';
const KICOM_GATEWAY = 'KiCom';
const KICOM_MAX_ECHO_LEN = 200;
const KICOM_MAX_PROPOSAL_BYTES = 4096;
const KICOM_PROPOSAL_TTL = 1800;
const KICOM_MAX_PENDING = 20;
const KICOM_MAX_HISTORY_PER_FILE = 50;
const KICOM_MAX_READ_BYTES = 32768;
const KICOM_MAX_MEMORY_PROPOSAL_BYTES = 6144;
const KICOM_MAX_POST_BYTES = 4194304;
const KICOM_MAX_MEMORY_ARCHIVE_SNAPSHOT_BYTES = 262144;
const KICOM_INTENT_TTL = 120;
const KICOM_MAX_INTENTS = 20;
const KICOM_MAX_DEPLOY_HISTORY = 100;
const KICOM_HEALTH_TIMEOUT = 5;
const KICOM_MAX_DEPLOY_BACKUP_BYTES = 262144;
const KICOM_MAX_PACKAGE_FILES = 20;
const KICOM_MAX_PACKAGE_BYTES = 1048576;
const KICOM_MAX_PACKAGE_BACKUP_BYTES = 2097152;
const KICOM_MAX_SELF_UPDATE_ZIP_BYTES = 8388608;
const KICOM_MAX_SELF_UPDATE_FILES = 240;
const KICOM_MAX_SELF_UPDATE_UNCOMPRESSED_BYTES = 16777216;
const KICOM_MAX_SELF_UPDATE_BACKUP_BYTES = 16777216;
const KICOM_MAX_SELF_UPDATE_HISTORY = 20;
const KICOM_UPDATE_FEED_MAX_BYTES = 262144;
const KICOM_UPDATE_CHANNEL_MIN_INTERVAL = 60;
const KICOM_UPDATE_PUSH_MAX_ATTEMPTS_PER_MINUTE = 12;

function kicomBaseDir(): string { return __DIR__; }
function kicomVarDir(): string { return kicomBaseDir() . '/var'; }
function kicomPendingDir(): string { return kicomVarDir() . '/pending'; }
function kicomHistoryDir(): string { return kicomVarDir() . '/history'; }
function kicomTempDir(): string { return kicomVarDir() . '/tmp'; }
function kicomStageDir(): string { return kicomBaseDir() . '/stage'; }
function kicomMemorySeedDir(): string { return kicomBaseDir() . '/memory'; }
function kicomMemoryStateDir(): string { return kicomVarDir() . '/project_memory'; }
function kicomMemoryHistoryDir(): string { return kicomVarDir() . '/memory_history'; }
function kicomIntentDir(): string { return kicomVarDir() . '/intents'; }
function kicomDeployBackupDir(): string { return kicomVarDir() . '/deploy_backups'; }
function kicomDeployHistoryDir(): string { return kicomVarDir() . '/deployments'; }
function kicomDeployTargetsFile(): string { return kicomVarDir() . '/deploy_targets.php'; }
function kicomMemoryDir(): string { return kicomMemoryStateDir(); }
function kicomConfigFile(): string { return kicomVarDir() . '/config.php'; }
function kicomSelfUpdateDir(): string { return kicomVarDir() . '/self_update'; }
function kicomSelfUpdatePackagesDir(): string { return kicomSelfUpdateDir() . '/packages'; }
function kicomSelfUpdateWorkDir(): string { return kicomSelfUpdateDir() . '/work'; }
function kicomSelfUpdateHistoryDir(): string { return kicomSelfUpdateDir() . '/history'; }
function kicomSelfUpdatePendingFile(): string { return kicomSelfUpdateDir() . '/pending.json'; }
function kicomUpdateChannelsFile(): string { return kicomSelfUpdateDir() . '/channels.json'; }
function kicomUpdateChannelStateFile(): string { return kicomSelfUpdateDir() . '/channel_state.json'; }
function kicomUpdatePushRateFile(): string { return kicomSelfUpdateDir() . '/push_rate.json'; }

if(!defined('KICOM_GUARDIAN_EMBEDDED')) define('KICOM_GUARDIAN_EMBEDDED',true);
require_once __DIR__ . '/guardian.php';
require_once __DIR__ . '/living.php';

function kicomTextLen(string $value): int {
    return function_exists('mb_strlen') ? (int)mb_strlen($value, 'UTF-8') : strlen($value);
}
function kicomTextSubstr(string $value, int $start, int $length): string {
    return function_exists('mb_substr') ? (string)mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}
function kicomRequestId(): string {
    try { return strtoupper(bin2hex(random_bytes(4))); }
    catch (Throwable $e) { return strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8)); }
}
function kicomSafeId(string $id): ?string {
    $id = strtolower(trim($id));
    return preg_match('/^[a-f0-9]{8,80}$/', $id) ? $id : null;
}
function kicomDenyRules(): string {
    return "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";
}
function kicomEnsureStorage(): bool {
    foreach ([kicomVarDir(), kicomPendingDir(), kicomHistoryDir(), kicomTempDir(), kicomStageDir(), kicomMemoryStateDir(), kicomMemoryHistoryDir(), kicomIntentDir(), kicomDeployBackupDir(), kicomDeployHistoryDir(), kicomSelfUpdateDir(), kicomSelfUpdatePackagesDir(), kicomSelfUpdateWorkDir(), kicomSelfUpdateHistoryDir()] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    }
    foreach ([kicomVarDir(), kicomStageDir()] as $dir) {
        $file = $dir . '/.htaccess';
        if (!is_file($file)) @file_put_contents($file, kicomDenyRules(), LOCK_EX);
    }
    /* Seed canonical runtime memory only once. Future code-package uploads must not overwrite it. */
    $resources=kicomMemoryResources();
    $stateFile=kicomMemoryStateDir().'/'.$resources['PROJECT_STATE'];
    if (is_file($stateFile)) {
        /* Never silently mix a persistent memory set with seed files from a later package. */
        foreach ($resources as $filename) if (!is_file(kicomMemoryStateDir().'/'.$filename)) return false;
        return kicomLivingEnsure();
    }
    $seedState=kicomMemorySeedDir().'/'.$resources['PROJECT_STATE'];
    $seedStateRaw=@file_get_contents($seedState);
    if ($seedStateRaw===false || !str_contains((string)$seedStateRaw,'VERSION "'.KICOM_VERSION.'"')) return false;
    foreach ($resources as $filename) {
        $seed = kicomMemorySeedDir() . '/' . $filename;
        if (!is_file($seed)) return false;
        $raw = @file_get_contents($seed);
        $target = kicomMemoryStateDir() . '/' . $filename;
        if ($raw === false || @file_put_contents($target, $raw, LOCK_EX) === false) return false;
        @chmod($target, 0600);
    }
    return kicomLivingEnsure();
}
function kicomCleanupPending(): void {
    $now = time();
    foreach (glob(kicomPendingDir() . '/*.json') ?: [] as $file) {
        if (is_file($file) && ($now - (int)@filemtime($file)) > KICOM_PROPOSAL_TTL) @unlink($file);
    }
}

function kicomCleanupIntents(): void {
    $now=time();
    foreach(glob(kicomIntentDir().'/*.json')?:[] as $file){
        $row=json_decode((string)@file_get_contents($file),true);
        $expired=!is_array($row) || (int)($row['expires_at']??0)<$now;
        if($expired) @unlink($file);
    }
}
function kicomCreateIntent(string $operation,string $target): array {
    if(!kicomEnsureStorage()) return ['ok'=>false,'code'=>'STORAGE_UNAVAILABLE'];
    kicomCleanupIntents();
    $files=glob(kicomIntentDir().'/*.json')?:[];
    if(count($files)>=KICOM_MAX_INTENTS) return ['ok'=>false,'code'=>'INTENT_LIMIT'];
    $operation=strtoupper(trim($operation)); $target=trim($target);
    $allowed=['STAGE_PROPOSE','WORKSPACE_PROPOSE','WORKSPACE_ROLLBACK','MEMORY_PROPOSE','MEMORY_PATCH_PROPOSE','MEMORY_ROLLBACK','MEMORY_ARCHIVE_BEGIN','DEPLOY_PROPOSE','DEPLOY_ROLLBACK','DEPLOY_PACKAGE_PROPOSE','DEPLOY_PACKAGE_ROLLBACK'];
    if(!in_array($operation,$allowed,true) || $target==='' || strlen($target)>180) return ['ok'=>false,'code'=>'INVALID_INTENT'];
    try{$token=bin2hex(random_bytes(16));}catch(Throwable $e){$token=strtolower(hash('sha256',uniqid('',true)));}
    $row=['token'=>$token,'operation'=>$operation,'target'=>$target,'created_at'=>gmdate('c'),'expires_at'=>time()+KICOM_INTENT_TTL];
    $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if($json===false || @file_put_contents(kicomIntentDir().'/'.$token.'.json',$json,LOCK_EX)===false) return ['ok'=>false,'code'=>'INTENT_WRITE_FAILED'];
    @chmod(kicomIntentDir().'/'.$token.'.json',0600);
    return ['ok'=>true,'token'=>$token,'expires_in'=>KICOM_INTENT_TTL];
}
function kicomConsumeIntent(string $token,string $operation,string $target): array {
    if(!preg_match('/^[a-f0-9]{32,64}$/',$token)) return ['ok'=>false,'code'=>'INTENT_REQUIRED'];
    $file=kicomIntentDir().'/'.$token.'.json'; if(!is_file($file)) return ['ok'=>false,'code'=>'INTENT_NOT_FOUND'];
    $row=json_decode((string)@file_get_contents($file),true); @unlink($file);
    if(!is_array($row)) return ['ok'=>false,'code'=>'INTENT_INVALID'];
    if((int)($row['expires_at']??0)<time()) return ['ok'=>false,'code'=>'INTENT_EXPIRED'];
    if(!hash_equals((string)($row['operation']??''),strtoupper(trim($operation))) || !hash_equals((string)($row['target']??''),trim($target))) return ['ok'=>false,'code'=>'INTENT_MISMATCH'];
    return ['ok'=>true];
}
function kicomMemoryPatchCandidate(string $name,string $baseSha,string $find,string $replace): array {
    $name=strtoupper(trim($name)); $res=kicomReadMemoryResource($name);
    if($res===null) return ['ok'=>false,'code'=>'UNKNOWN_MEMORY_RESOURCE'];
    $baseSha=strtolower(trim($baseSha));
    if(!preg_match('/^[a-f0-9]{64}$/',$baseSha)) return ['ok'=>false,'code'=>'BASE_REQUIRED','current_sha256'=>$res['sha256']];
    if(!hash_equals((string)$res['sha256'],$baseSha)) return ['ok'=>false,'code'=>'BASE_CONFLICT','current_sha256'=>$res['sha256']];
    if($find==='' || strlen($find)>2048 || strlen($replace)>4096) return ['ok'=>false,'code'=>'PATCH_SIZE_INVALID'];
    $count=substr_count((string)$res['raw'],$find);
    if($count!==1) return ['ok'=>false,'code'=>'PATCH_MATCH_COUNT','matches'=>$count];
    $candidate=str_replace($find,$replace,(string)$res['raw'],$replaced);
    if($replaced!==1) return ['ok'=>false,'code'=>'PATCH_APPLY_FAILED'];
    if(strlen($candidate)>KICOM_MAX_MEMORY_PROPOSAL_BYTES) return ['ok'=>false,'code'=>'CONTENT_TOO_LARGE'];
    $v=kicomValidateMemoryContent($name,$candidate);
    if($v['status']==='error') return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];
    return ['ok'=>true,'resource'=>$name,'content'=>$candidate,'base_sha256'=>$baseSha,'sha256'=>hash('sha256',$candidate),'validation'=>$v];
}

function kicomSafeRelativePath(string $path): ?string {
    $path = trim(str_replace('\\', '/', $path));
    $path = ltrim($path, '/');
    if ($path === '' || strlen($path) > 180) return null;
    if (str_contains($path, "\0") || str_contains($path, '..')) return null;
    if (preg_match('~(^|/)[.]~', $path)) return null;
    if (!preg_match('~^[A-Za-z0-9_./-]+$~', $path)) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowed = ['txt','md','json','php','html','htm','css','js'];
    return in_array($ext, $allowed, true) ? $path : null;
}
function kicomDecodeBase64Url(string $value, bool $allowEmpty = false): ?string {
    if ($value === '') return $allowEmpty ? '' : null;
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) $value .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($value, true);
    return $decoded === false ? null : $decoded;
}
function kicomEncodeBase64Url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
function kicomCurrentHash(string $path): string {
    $full = kicomStageDir() . '/' . $path;
    if (!is_file($full)) return 'NEW';
    return hash_file('sha256', $full) ?: '';
}
function kicomReadStage(string $path): ?string {
    $full = kicomStageDir() . '/' . $path;
    if (!is_file($full)) return null;
    $raw = @file_get_contents($full);
    return $raw === false ? null : (string)$raw;
}
function kicomListStageFiles(): array {
    $root = kicomStageDir();
    $result = [];
    if (!is_dir($root)) return $result;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = str_replace('\\','/', substr($file->getPathname(), strlen($root)+1));
        if ($path === '.htaccess') continue;
        $result[] = [
            'path'=>$path,
            'bytes'=>$file->getSize(),
            'sha256'=>hash_file('sha256',$file->getPathname()) ?: '',
            'revisions'=>kicomHistoryCount($path),
        ];
        if (count($result) >= 100) break;
    }
    usort($result, fn($a,$b)=>strcmp($a['path'],$b['path']));
    return $result;
}
function kicomValidateContent(string $path, string $content): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'json') {
        json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE
            ? ['status'=>'ok','message'=>'JSON_VALID']
            : ['status'=>'error','message'=>'JSON_INVALID:' . json_last_error_msg()];
    }
    if ($ext === 'php') {
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) return ['status'=>'warn','message'=>'PHP_LINT_UNAVAILABLE'];
        if (!kicomEnsureStorage()) return ['status'=>'error','message'=>'STORAGE_UNAVAILABLE'];
        $tmp = kicomTempDir() . '/lint-' . strtolower(kicomRequestId()) . '.php';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) return ['status'=>'error','message'=>'TEMP_WRITE_FAILED'];
        $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1';
        $lines = []; $code = 1; @exec($cmd, $lines, $code); @unlink($tmp);
        if ($code === 0) return ['status'=>'ok','message'=>'PHP_SYNTAX_OK'];
        $diag=implode("\n",array_map('strval',$lines));
        if (preg_match('/(?:parse error|syntax error|errors parsing)/i',$diag)) return ['status'=>'error','message'=>'PHP_SYNTAX_ERROR'];
        return ['status'=>'warn','message'=>'PHP_LINT_UNAVAILABLE'];
    }
    return ['status'=>'ok','message'=>'NO_PARSER_REQUIRED'];
}
function kicomValidateStage(string $path): array {
    $raw = kicomReadStage($path);
    return $raw === null ? ['status'=>'error','message'=>'FILE_NOT_FOUND'] : kicomValidateContent($path, $raw);
}

function kicomHistoryKey(string $path): string { return hash('sha256', $path); }
function kicomHistoryPathDir(string $path): string { return kicomHistoryDir() . '/' . kicomHistoryKey($path); }
function kicomHistoryFiles(string $path): array {
    $dir = kicomHistoryPathDir($path);
    if (!is_dir($dir)) return [];
    $rows = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $row = json_decode((string)@file_get_contents($file), true);
        if (is_array($row) && (($row['path'] ?? null) === $path)) $rows[] = $row;
    }
    usort($rows, fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')) ?: strcmp((string)($b['revision']??''),(string)($a['revision']??'')));
    return $rows;
}
function kicomHistoryCount(string $path): int { return count(kicomHistoryFiles($path)); }
function kicomHistoryGet(string $path, string $revision): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/', $revision)) return null;
    $file = kicomHistoryPathDir($path) . '/' . $revision . '.json';
    if (!is_file($file)) return null;
    $row = json_decode((string)@file_get_contents($file), true);
    return is_array($row) && (($row['path'] ?? null) === $path) ? $row : null;
}
function kicomRecordRevision(string $path, ?string $content, string $action): ?string {
    if (!kicomEnsureStorage()) return null;
    $dir = kicomHistoryPathDir($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
    $sha = $content === null ? 'deleted' : hash('sha256', $content);
    $revision = gmdate('YmdHis') . '-' . substr($sha,0,12) . '-' . strtolower(kicomRequestId());
    $row = [
        'revision'=>$revision,
        'created_at'=>gmdate('c'),
        'path'=>$path,
        'action'=>$action,
        'bytes'=>$content === null ? 0 : strlen($content),
        'sha256'=>$content === null ? null : $sha,
        'content_b64'=>$content === null ? null : base64_encode($content),
    ];
    $json = json_encode($row, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($dir . '/' . $revision . '.json', $json, LOCK_EX) === false) return null;
    $files = glob($dir . '/*.json') ?: [];
    if (count($files) > KICOM_MAX_HISTORY_PER_FILE) {
        usort($files, fn($a,$b)=>(int)@filemtime($a) <=> (int)@filemtime($b));
        foreach (array_slice($files, 0, count($files)-KICOM_MAX_HISTORY_PER_FILE) as $old) @unlink($old);
    }
    return $revision;
}
function kicomEnsureBaseline(string $path): void {
    if (kicomHistoryCount($path) > 0) return;
    $current = kicomReadStage($path);
    if ($current !== null) kicomRecordRevision($path, $current, 'baseline');
}
function kicomAtomicStageWrite(string $path, string $content, string $action, ?string $expectedBase = null): array {
    $path = kicomSafeRelativePath($path);
    if ($path === null) return ['ok'=>false,'code'=>'INVALID_PATH'];
    $currentHash = kicomCurrentHash($path);
    if ($expectedBase !== null && $expectedBase !== '' && !hash_equals($currentHash, $expectedBase)) {
        return ['ok'=>false,'code'=>'BASE_CONFLICT','current_sha256'=>$currentHash];
    }
    kicomEnsureBaseline($path);
    $target = kicomStageDir() . '/' . $path;
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) return ['ok'=>false,'code'=>'TARGET_DIR_FAILED'];
    $tmp = $target . '.tmp-' . strtolower(kicomRequestId());
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) return ['ok'=>false,'code'=>'WRITE_FAILED'];
    @chmod($tmp,0600);
    if (!@rename($tmp,$target)) { @unlink($tmp); return ['ok'=>false,'code'=>'RENAME_FAILED']; }
    @chmod($target,0600);
    $revision = kicomRecordRevision($path, $content, $action);
    return ['ok'=>true,'revision'=>$revision ?? '','sha256'=>hash('sha256',$content),'bytes'=>strlen($content)];
}
function kicomDeleteStageWithHistory(string $path): array {
    $path = kicomSafeRelativePath($path);
    if ($path === null) return ['ok'=>false,'code'=>'INVALID_PATH'];
    $target = kicomStageDir() . '/' . $path;
    if (!is_file($target)) return ['ok'=>false,'code'=>'FILE_NOT_FOUND'];
    kicomEnsureBaseline($path);
    if (!@unlink($target)) return ['ok'=>false,'code'=>'DELETE_FAILED'];
    $revision = kicomRecordRevision($path, null, 'delete');
    return ['ok'=>true,'revision'=>$revision ?? ''];
}
function kicomCreateProposal(array $proposal): array {
    kicomCleanupPending();
    $pending = glob(kicomPendingDir().'/*.json') ?: [];
    if (count($pending) >= KICOM_MAX_PENDING) return ['ok'=>false,'code'=>'PENDING_LIMIT'];
    $id = strtolower(kicomRequestId());
    $proposal['id'] = $id;
    $proposal['created_at'] = gmdate('c');
    $json = json_encode($proposal, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents(kicomPendingDir().'/'.$id.'.json', $json, LOCK_EX) === false) {
        return ['ok'=>false,'code'=>'PENDING_WRITE_FAILED'];
    }
    return ['ok'=>true,'id'=>$id];
}

function kicomUnifiedDiff(string $old, string $new, int $maxLines = 260): array {
    $a = preg_split('/\r?\n/', $old) ?: [];
    $b = preg_split('/\r?\n/', $new) ?: [];
    if (count($a) > $maxLines || count($b) > $maxLines) return ['ok'=>false,'code'=>'DIFF_LINE_LIMIT'];
    $n=count($a); $m=count($b); $dp=array_fill(0,$n+1,array_fill(0,$m+1,0));
    for($i=$n-1;$i>=0;$i--) for($j=$m-1;$j>=0;$j--) $dp[$i][$j]=($a[$i]===$b[$j])?1+$dp[$i+1][$j+1]:max($dp[$i+1][$j],$dp[$i][$j+1]);
    $i=0;$j=0;$lines=['--- current','+++ proposed'];
    while($i<$n && $j<$m){
        if($a[$i]===$b[$j]){ $lines[]=' '.$a[$i]; $i++;$j++; }
        elseif($dp[$i+1][$j] >= $dp[$i][$j+1]){ $lines[]='-'.$a[$i]; $i++; }
        else { $lines[]='+'.$b[$j]; $j++; }
    }
    while($i<$n){$lines[]='-'.$a[$i++];}
    while($j<$m){$lines[]='+'.$b[$j++];}
    return ['ok'=>true,'lines'=>$lines,'changed'=>($old!==$new)];
}

function kicomMemoryResources(): array {
    return [
        'PROJECT_STATE'=>'project_state.kcl','ARCHITECTURE'=>'architecture.kcl','PROTOCOL'=>'protocol.kcl',
        'DECISIONS'=>'decisions.kcl','CHANGELOG'=>'changelog.kcl','NEXT'=>'next.kcl',
    ];
}
function kicomReadMemoryResource(string $name): ?array {
    $name=strtoupper(trim($name));
    $resources=kicomMemoryResources(); if(!isset($resources[$name])) return null;
    if(!kicomEnsureStorage()) return null;
    $file=kicomMemoryStateDir().'/'.$resources[$name]; if(!is_file($file)) return null;
    $raw=@file_get_contents($file); if($raw===false||$raw===''||strlen($raw)>65536)return null;
    return ['name'=>$name,'content'=>rtrim((string)$raw,"\r\n"),'raw'=>(string)$raw,'sha256'=>hash('sha256',(string)$raw),'bytes'=>strlen((string)$raw)];
}
function kicomMemoryCurrentHash(string $name): string {
    $res=kicomReadMemoryResource($name); return $res===null?'':(string)$res['sha256'];
}
function kicomValidateMemoryContent(string $name, string $content): array {
    $name=strtoupper(trim($name));
    if(!isset(kicomMemoryResources()[$name])) return ['status'=>'error','message'=>'UNKNOWN_MEMORY_RESOURCE'];
    if($content===''||strlen($content)>KICOM_MAX_MEMORY_PROPOSAL_BYTES) return ['status'=>'error','message'=>'MEMORY_SIZE_INVALID'];
    if(str_contains($content,"\0") || preg_match('//u',$content)!==1) return ['status'=>'error','message'=>'MEMORY_ENCODING_INVALID'];
    if(str_contains($content,'<?') || str_contains($content,'?>')) return ['status'=>'error','message'=>'MEMORY_CODE_MARKER_FORBIDDEN'];
    $markers=[
        'PROJECT_STATE'=>['PROJECT kicom','END_PROJECT kicom'],
        'ARCHITECTURE'=>['ARCHITECTURE kicom','END_ARCHITECTURE kicom'],
        'PROTOCOL'=>['PROTOCOL KCL/1','END_PROTOCOL KCL/1'],
        'DECISIONS'=>['DECISIONS kicom','END_DECISIONS kicom'],
        'CHANGELOG'=>['CHANGELOG kicom','END_CHANGELOG kicom'],
        'NEXT'=>['NEXT kicom','END_NEXT kicom'],
    ];
    $trim=trim($content); [$start,$end]=$markers[$name];
    if(!str_starts_with($trim,$start) || !str_ends_with($trim,$end)) return ['status'=>'error','message'=>'KCL_STRUCTURE_INVALID'];
    foreach(preg_split('/\r?\n/',$content)?:[] as $line) if(strlen($line)>2048) return ['status'=>'error','message'=>'KCL_LINE_TOO_LONG'];
    if($name==='PROJECT_STATE') {
        foreach(['FACT direct_shell=false','FACT arbitrary_remote_fetch=false','FACT canonical_memory_via="?q=BOOTSTRAP"'] as $required) {
            if(!str_contains($content,$required)) return ['status'=>'error','message'=>'PROJECT_STATE_SAFETY_SENTINEL_MISSING'];
        }
        if(!str_contains($content,'FACT production_write=false') && !str_contains($content,'FACT production_write="allowlisted-human-approved"')) return ['status'=>'error','message'=>'PROJECT_STATE_DEPLOYMENT_SENTINEL_MISSING'];
    }
    return ['status'=>'ok','message'=>'KCL_STRUCTURE_OK'];
}
function kicomListMemoryResources(): array {
    $rows=[];
    foreach(array_keys(kicomMemoryResources()) as $name){
        $res=kicomReadMemoryResource($name); if($res===null) continue;
        $rows[]=['resource'=>$name,'bytes'=>$res['bytes'],'sha256'=>$res['sha256'],'revisions'=>kicomMemoryHistoryCount($name)];
    }
    return $rows;
}
function kicomMemoryHistoryResourceDir(string $name): string { return kicomMemoryHistoryDir().'/'.strtoupper($name); }
function kicomMemoryHistoryFiles(string $name): array {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name])) return [];
    $dir=kicomMemoryHistoryResourceDir($name); if(!is_dir($dir)) return [];
    $rows=[]; foreach(glob($dir.'/*.json')?:[] as $file){$row=json_decode((string)@file_get_contents($file),true);if(is_array($row)&&(($row['resource']??null)===$name))$rows[]=$row;}
    usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')) ?: strcmp((string)($b['revision']??''),(string)($a['revision']??'')));
    return $rows;
}
function kicomMemoryHistoryCount(string $name): int { return count(kicomMemoryHistoryFiles($name)); }
function kicomMemoryHistoryGet(string $name,string $revision): ?array {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name])||!preg_match('/^[A-Za-z0-9_-]{8,100}$/',$revision))return null;
    $file=kicomMemoryHistoryResourceDir($name).'/'.$revision.'.json'; if(!is_file($file))return null;
    $row=json_decode((string)@file_get_contents($file),true); return is_array($row)&&(($row['resource']??null)===$name)?$row:null;
}
function kicomRecordMemoryRevision(string $name,string $content,string $action): ?string {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name])||!kicomEnsureStorage())return null;
    $dir=kicomMemoryHistoryResourceDir($name); if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return null;
    $sha=hash('sha256',$content); $revision=gmdate('YmdHis').'-'.substr($sha,0,12).'-'.strtolower(kicomRequestId());
    $row=['revision'=>$revision,'created_at'=>gmdate('c'),'resource'=>$name,'action'=>$action,'bytes'=>strlen($content),'sha256'=>$sha,'content_b64'=>base64_encode($content)];
    $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT); if($json===false||@file_put_contents($dir.'/'.$revision.'.json',$json,LOCK_EX)===false)return null;
    $files=glob($dir.'/*.json')?:[]; if(count($files)>KICOM_MAX_HISTORY_PER_FILE){usort($files,fn($a,$b)=>(int)@filemtime($a)<=>(int)@filemtime($b));foreach(array_slice($files,0,count($files)-KICOM_MAX_HISTORY_PER_FILE) as $old)@unlink($old);}
    return $revision;
}
function kicomEnsureMemoryBaseline(string $name): void {
    if(kicomMemoryHistoryCount($name)>0)return; $res=kicomReadMemoryResource($name); if($res!==null)kicomRecordMemoryRevision($name,(string)$res['raw'],'baseline');
}
function kicomAtomicMemoryWrite(string $name,string $content,string $action,string $expectedBase): array {
    $name=strtoupper(trim($name)); if(!isset(kicomMemoryResources()[$name]))return ['ok'=>false,'code'=>'UNKNOWN_MEMORY_RESOURCE'];
    $v=kicomValidateMemoryContent($name,$content); if($v['status']==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];
    $current=kicomMemoryCurrentHash($name); if($current===''||!hash_equals($current,$expectedBase))return ['ok'=>false,'code'=>'BASE_CONFLICT','current_sha256'=>$current];
    kicomEnsureMemoryBaseline($name); $target=kicomMemoryStateDir().'/'.kicomMemoryResources()[$name]; $tmp=$target.'.tmp-'.strtolower(kicomRequestId());
    if(@file_put_contents($tmp,$content,LOCK_EX)===false)return ['ok'=>false,'code'=>'WRITE_FAILED']; @chmod($tmp,0600);
    if(!@rename($tmp,$target)){@unlink($tmp);return ['ok'=>false,'code'=>'RENAME_FAILED'];} @chmod($target,0600);
    $revision=kicomRecordMemoryRevision($name,$content,$action);
    return ['ok'=>true,'revision'=>$revision??'','sha256'=>hash('sha256',$content),'bytes'=>strlen($content)];
}


/* KiCom 0.6: controlled deployment layer. Target filesystem roots are configured only in admin.php
   and never returned through the public protocol. The client only sees target aliases. */
function kicomDeployTargetAlias(string $alias): ?string {
    $alias=strtolower(trim($alias));
    return preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/',$alias)?$alias:null;
}
function kicomDeployClass(string $class): ?string {
    $class=strtolower(trim($class));
    return in_array($class,['test','staging','production'],true)?$class:null;
}
function kicomDeployRoot(string $root): ?string {
    $root=trim($root); if($root===''||str_contains($root,"\0"))return null;
    $real=realpath($root); if($real===false||!is_dir($real))return null;
    $base=realpath(kicomBaseDir());
    if($base!==false){$a=rtrim(str_replace('\\','/',$real),'/').'/';$b=rtrim(str_replace('\\','/',$base),'/').'/';if(str_starts_with($a,$b))return null;}
    return $real;
}
function kicomValidateHealthUrl(string $url): ?string {
    $url=trim($url); if($url==='')return '';
    $p=parse_url($url); if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass']))return null;
    return $url;
}
function kicomLoadDeployTargets(): array {
    $f=kicomDeployTargetsFile(); if(!is_file($f))return [];
    $x=require $f; if(!is_array($x))return [];
    $out=[];
    foreach($x as $alias=>$row){
        $a=kicomDeployTargetAlias((string)$alias); if($a===null||!is_array($row))continue;
        $root=kicomDeployRoot((string)($row['root']??'')); if($root===null)continue;
        $health=kicomValidateHealthUrl((string)($row['health_url']??'')); if($health===null)continue;
        /* Backward compatibility: 0.6.0 targets without an explicit class become test targets. */
        $class=kicomDeployClass((string)($row['class']??'test')); if($class===null)continue;
        if($class==='production' && $health==='')continue;
        $out[$a]=[
            'root'=>$root,
            'health_url'=>$health,
            'enabled'=>!empty($row['enabled']),
            'label'=>(string)($row['label']??$a),
            'class'=>$class,
        ];
    }
    return $out;
}
function kicomSaveDeployTargets(array $targets): bool {
    $clean=[];
    foreach($targets as $alias=>$row){
        $a=kicomDeployTargetAlias((string)$alias); if($a===null||!is_array($row))return false;
        $root=kicomDeployRoot((string)($row['root']??'')); if($root===null)return false;
        $health=kicomValidateHealthUrl((string)($row['health_url']??'')); if($health===null)return false;
        $class=kicomDeployClass((string)($row['class']??'test')); if($class===null)return false;
        if($class==='production' && $health==='')return false;
        $clean[$a]=[
            'root'=>$root,
            'health_url'=>$health,
            'enabled'=>!empty($row['enabled']),
            'label'=>substr(trim((string)($row['label']??$a)),0,80),
            'class'=>$class,
        ];
    }
    $php="<?php\nreturn ".var_export($clean,true).";\n";
    $ok=@file_put_contents(kicomDeployTargetsFile(),$php,LOCK_EX)!==false; if($ok)@chmod(kicomDeployTargetsFile(),0600); return $ok;
}
function kicomPublicDeployTargets(): array {
    $rows=[];
    foreach(kicomLoadDeployTargets() as $alias=>$r)$rows[]=[
        'alias'=>$alias,
        'label'=>$r['label'],
        'enabled'=>$r['enabled'],
        'healthcheck'=>$r['health_url']!=='',
        'class'=>$r['class'],
    ];
    return $rows;
}
function kicomDeployTarget(string $alias,bool $requireEnabled=true): ?array {
    $a=kicomDeployTargetAlias($alias); if($a===null)return null;$all=kicomLoadDeployTargets(); if(!isset($all[$a]))return null;if($requireEnabled&&!$all[$a]['enabled'])return null;return ['alias'=>$a]+$all[$a];
}
function kicomTargetFile(string $alias,string $dest): ?array {
    $t=kicomDeployTarget($alias,true);$dest=kicomSafeRelativePath($dest);if($t===null||$dest===null)return null;
    $full=rtrim((string)$t['root'],DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$dest);
    $parent=dirname($full);$rootReal=realpath((string)$t['root']);$parentReal=is_dir($parent)?realpath($parent):realpath(dirname($parent));
    if($rootReal===false)return null;
    $rootNorm=rtrim(str_replace('\\','/',$rootReal),'/').'/';
    $check=$parentReal!==false?rtrim(str_replace('\\','/',$parentReal),'/').'/':$rootNorm;
    if(!str_starts_with($check,$rootNorm))return null;
    return ['target'=>$t,'dest'=>$dest,'full'=>$full];
}
function kicomTargetHash(string $alias,string $dest): string {
    $r=kicomTargetFile($alias,$dest);if($r===null)return '';$f=$r['full'];if(!is_file($f))return 'NEW';return hash_file('sha256',$f)?:'';
}
function kicomHealthCheck(string $alias): array {
    $t=kicomDeployTarget($alias,true);if($t===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];$url=(string)$t['health_url'];if($url==='')return ['ok'=>true,'configured'=>false,'http_code'=>null];
    $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>KICOM_HEALTH_TIMEOUT,'ignore_errors'=>true,'header'=>"User-Agent: KiCom/".KICOM_VERSION." HealthCheck\r\nConnection: close\r\n"]]);
    $body=@file_get_contents($url,false,$ctx,0,1);$headers=$http_response_header??[];$code=0;foreach($headers as $h){if(preg_match('~^HTTP/\\S+\\s+(\\d{3})~i',$h,$m)){$code=(int)$m[1];break;}}
    return ['ok'=>$code>=200&&$code<400,'configured'=>true,'http_code'=>$code,'code'=>$code?null:'HEALTH_REQUEST_FAILED'];
}
function kicomDeployDryRun(string $alias,string $workspacePath,string $dest): array {
    $workspacePath=kicomSafeRelativePath($workspacePath); $dest=kicomSafeRelativePath($dest); $t=kicomDeployTarget($alias,true);
    if($workspacePath===null||$dest===null)return ['ok'=>false,'code'=>'INVALID_PATH'];
    if($t===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    if((string)$t['class']==='production' && (string)$t['health_url']==='')return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_REQUIRED'];
    $content=kicomReadStage($workspacePath); if($content===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND'];
    $tf0=kicomTargetFile($alias,$dest); if($tf0!==null&&is_file((string)$tf0['full'])&&filesize((string)$tf0['full'])>KICOM_MAX_DEPLOY_BACKUP_BYTES)return ['ok'=>false,'code'=>'TARGET_TOO_LARGE_TO_BACKUP'];
    $v=kicomValidateContent($dest,$content); if($v['status']==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','validation'=>$v['message']];
    $tf=kicomTargetFile($alias,$dest); if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];
    $current=kicomTargetHash($alias,$dest); $parent=dirname((string)$tf['full']); $writable=is_dir($parent)?is_writable($parent):is_writable((string)$t['root']);
    $health=kicomHealthCheck($alias);
    if((string)$t['class']==='production' && (!$health['configured'] || !$health['ok']))return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$health];
    return [
        'ok'=>true,'target'=>$t['alias'],'target_class'=>$t['class'],'workspace_path'=>$workspacePath,'dest_path'=>$dest,
        'workspace_sha256'=>hash('sha256',$content),'target_sha256'=>$current,'bytes'=>strlen($content),'validation'=>$v['message'],
        'target_writable'=>$writable,'healthcheck_configured'=>$t['health_url']!=='','healthcheck_preflight_ok'=>$health['ok'],
        'healthcheck_http_code'=>$health['http_code'],
    ];
}
function kicomCreateDeployProposal(string $alias,string $workspacePath,string $dest,string $workspaceSha,string $targetBase,string $transport): array {
    $d=kicomDeployDryRun($alias,$workspacePath,$dest);if(!$d['ok'])return $d;if(!$d['target_writable'])return ['ok'=>false,'code'=>'TARGET_NOT_WRITABLE'];
    $workspaceSha=strtolower(trim($workspaceSha));$targetBase=strtoupper(trim($targetBase))==='NEW'?'NEW':strtolower(trim($targetBase));
    if(!preg_match('/^[a-f0-9]{64}$/',$workspaceSha)||($targetBase!=='NEW'&&!preg_match('/^[a-f0-9]{64}$/',$targetBase)))return ['ok'=>false,'code'=>'INVALID_HASH'];
    if(!hash_equals((string)$d['workspace_sha256'],$workspaceSha))return ['ok'=>false,'code'=>'WORKSPACE_CONFLICT','current_workspace_sha256'=>$d['workspace_sha256']];
    if(!hash_equals((string)$d['target_sha256'],$targetBase))return ['ok'=>false,'code'=>'TARGET_CONFLICT','current_target_sha256'=>$d['target_sha256']];
    $pr=kicomCreateProposal(['kind'=>'deploy_write','target'=>$d['target'],'target_class'=>$d['target_class'],'workspace_path'=>$d['workspace_path'],'dest_path'=>$d['dest_path'],'workspace_sha256'=>$workspaceSha,'target_base_sha256'=>$targetBase,'bytes'=>$d['bytes'],'sha256'=>$workspaceSha,'validation'=>['status'=>'ok','message'=>$d['validation']],'healthcheck_preflight_ok'=>$d['healthcheck_preflight_ok'],'healthcheck_http_code'=>$d['healthcheck_http_code'],'transport'=>$transport]);
    return $pr['ok']?['ok'=>true,'proposal_id'=>$pr['id']]+$d:$pr;
}
function kicomDeployHistoryFiles(): array {
    $rows=[];foreach(glob(kicomDeployHistoryDir().'/*.json')?:[] as $f){$r=json_decode((string)@file_get_contents($f),true);if(is_array($r))$rows[]=$r;}usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));return array_slice($rows,0,KICOM_MAX_DEPLOY_HISTORY);
}
function kicomDeployHistoryGet(string $id): ?array {
    if(!preg_match('/^[A-Za-z0-9_-]{8,100}$/',$id))return null;$f=kicomDeployHistoryDir().'/'.$id.'.json';if(!is_file($f))return null;$r=json_decode((string)@file_get_contents($f),true);return is_array($r)?$r:null;
}
function kicomRecordDeployment(array $row): ?string {
    $id=gmdate('YmdHis').'-'.strtolower(kicomRequestId());$row['id']=$id;$row['created_at']=gmdate('c');$json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);if($json===false||@file_put_contents(kicomDeployHistoryDir().'/'.$id.'.json',$json,LOCK_EX)===false)return null;@chmod(kicomDeployHistoryDir().'/'.$id.'.json',0600);$files=glob(kicomDeployHistoryDir().'/*.json')?:[];if(count($files)>KICOM_MAX_DEPLOY_HISTORY){usort($files,fn($a,$b)=>(int)@filemtime($a)<=>(int)@filemtime($b));foreach(array_slice($files,0,count($files)-KICOM_MAX_DEPLOY_HISTORY) as $old)@unlink($old);}return $id;
}
function kicomWriteDeployTarget(string $alias,string $dest,string $content): array {
    $r=kicomTargetFile($alias,$dest);if($r===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];$full=(string)$r['full'];$dir=dirname($full);if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))return ['ok'=>false,'code'=>'TARGET_DIR_FAILED'];$tmp=$full.'.kicom-tmp-'.strtolower(kicomRequestId());if(@file_put_contents($tmp,$content,LOCK_EX)===false)return ['ok'=>false,'code'=>'TARGET_WRITE_FAILED'];@chmod($tmp,0640);if(!@rename($tmp,$full)){@unlink($tmp);return ['ok'=>false,'code'=>'TARGET_RENAME_FAILED'];}@chmod($full,0640);return ['ok'=>true,'sha256'=>hash('sha256',$content)];
}
function kicomRestoreDeploymentBackup(array $hist,string $expectedCurrent): array {
    $alias=(string)($hist['target']??'');$dest=(string)($hist['dest_path']??'');$current=kicomTargetHash($alias,$dest);if($current===''||!hash_equals($current,$expectedCurrent))return ['ok'=>false,'code'=>'TARGET_CONFLICT','current_target_sha256'=>$current];
    $before=(string)($hist['before_sha256']??'');$backup=(string)($hist['backup_content_b64']??'');
    $r=kicomTargetFile($alias,$dest);if($r===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];
    if($before==='NEW'){
        if(is_file((string)$r['full'])&&!@unlink((string)$r['full']))return ['ok'=>false,'code'=>'ROLLBACK_DELETE_FAILED'];$after='NEW';
    }else{$content=base64_decode($backup,true);if($content===false||hash('sha256',$content)!==$before)return ['ok'=>false,'code'=>'BACKUP_CORRUPT'];$w=kicomWriteDeployTarget($alias,$dest,$content);if(!$w['ok'])return $w;$after=$w['sha256'];}
    return ['ok'=>true,'sha256'=>$after];
}
function kicomApplyDeployProposal(array $p): array {
    $kind=(string)($p['kind']??'');
    if($kind==='deploy_package_write')return kicomApplyDeployPackageWrite($p);
    if($kind==='deploy_package_rollback')return kicomApplyDeployPackageRollback($p);
    if($kind==='deploy_write'){
        $alias=(string)($p['target']??'');$wp=kicomSafeRelativePath((string)($p['workspace_path']??''));$dest=kicomSafeRelativePath((string)($p['dest_path']??''));$target=kicomDeployTarget($alias,true);if($wp===null||$dest===null||$target===null)return ['ok'=>false,'code'=>'INVALID_DEPLOY_PROPOSAL'];
        if((string)$target['class']==='production'){$pre=kicomHealthCheck($alias);if(!$pre['configured']||!$pre['ok'])return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$pre];}
        $content=kicomReadStage($wp);if($content===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND'];$wsha=hash('sha256',$content);if(!hash_equals($wsha,(string)($p['workspace_sha256']??'')))return ['ok'=>false,'code'=>'WORKSPACE_CONFLICT','current_workspace_sha256'=>$wsha];$base=(string)($p['target_base_sha256']??'');$current=kicomTargetHash($alias,$dest);if($current===''||!hash_equals($current,$base))return ['ok'=>false,'code'=>'TARGET_CONFLICT','current_target_sha256'=>$current];
        $v=kicomValidateContent($dest,$content);if($v['status']==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED'];$tf=kicomTargetFile($alias,$dest);if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];if(is_file((string)$tf['full'])&&filesize((string)$tf['full'])>KICOM_MAX_DEPLOY_BACKUP_BYTES)return ['ok'=>false,'code'=>'TARGET_TOO_LARGE_TO_BACKUP'];$beforeContent=is_file((string)$tf['full'])?@file_get_contents((string)$tf['full']):false;$beforeB64=$beforeContent===false?'':base64_encode((string)$beforeContent);
        $w=kicomWriteDeployTarget($alias,$dest,$content);if(!$w['ok'])return $w;$health=kicomHealthCheck($alias);$hist=['action'=>'deploy','target'=>$alias,'target_class'=>$target['class'],'dest_path'=>$dest,'workspace_path'=>$wp,'workspace_sha256'=>$wsha,'before_sha256'=>$base,'after_sha256'=>$w['sha256'],'backup_content_b64'=>$beforeB64,'health'=>$health,'status'=>$health['ok']?'deployed':'health_failed'];
        if(!$health['ok']){$restore=kicomRestoreDeploymentBackup($hist,$w['sha256']);$hist['status']=$restore['ok']?'rolled_back_healthcheck':'health_failed_rollback_failed';$hist['rollback_result']=$restore;$id=kicomRecordDeployment($hist);return ['ok'=>false,'code'=>$restore['ok']?'HEALTHCHECK_FAILED_ROLLED_BACK':'HEALTHCHECK_FAILED_ROLLBACK_FAILED','deployment_id'=>$id??'','health'=>$health,'rolled_back'=>$restore['ok']];}
        $id=kicomRecordDeployment($hist);return ['ok'=>true,'deployment_id'=>$id??'','sha256'=>$w['sha256'],'health'=>$health];
    }
    if($kind==='deploy_rollback'){
        $source=kicomDeployHistoryGet((string)($p['source_deployment']??''));if($source===null)return ['ok'=>false,'code'=>'DEPLOYMENT_NOT_FOUND'];$expected=(string)($p['target_base_sha256']??'');$r=kicomRestoreDeploymentBackup($source,$expected);if(!$r['ok'])return $r;$health=kicomHealthCheck((string)$source['target']);$rollbackTarget=kicomDeployTarget((string)$source['target'],true);$id=kicomRecordDeployment(['action'=>'rollback','target'=>$source['target'],'target_class'=>$rollbackTarget['class']??($source['target_class']??'test'),'dest_path'=>$source['dest_path'],'source_deployment'=>$source['id'],'before_sha256'=>$expected,'after_sha256'=>$r['sha256'],'status'=>$health['ok']?'rolled_back':'rolled_back_health_warning','health'=>$health,'backup_content_b64'=>'']);return ['ok'=>true,'deployment_id'=>$id??'','sha256'=>$r['sha256'],'health'=>$health];
    }
    return ['ok'=>false,'code'=>'UNKNOWN_DEPLOY_KIND'];
}
function kicomCreateDeployRollbackProposal(string $deploymentId,string $transport): array {
    $h=kicomDeployHistoryGet($deploymentId);if($h===null||($h['action']??'')!=='deploy')return ['ok'=>false,'code'=>'DEPLOYMENT_NOT_FOUND'];
    $target=kicomDeployTarget((string)$h['target'],true);if($target===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    $current=kicomTargetHash((string)$h['target'],(string)$h['dest_path']);if($current===''||!hash_equals($current,(string)$h['after_sha256']))return ['ok'=>false,'code'=>'TARGET_CONFLICT','current_target_sha256'=>$current];
    $pr=kicomCreateProposal(['kind'=>'deploy_rollback','target'=>$h['target'],'target_class'=>$target['class'],'dest_path'=>$h['dest_path'],'source_deployment'=>$deploymentId,'target_base_sha256'=>$current,'bytes'=>0,'sha256'=>(string)$h['before_sha256'],'transport'=>$transport]);
    return $pr['ok']?['ok'=>true,'proposal_id'=>$pr['id'],'target'=>$h['target'],'target_class'=>$target['class'],'dest_path'=>$h['dest_path'],'current_sha256'=>$current,'target_sha256'=>$h['before_sha256']]:$pr;
}

/* KiCom 0.7: transaction-like multi-file deployment packages.
   Packages are explicit JSON manifests stored in the versioned workspace under packages/*.json.
   Every file is preflighted and backed up before commit. Multi-file filesystem commits are not
   globally atomic; failures trigger immediate all-or-nothing restoration attempts. */
function kicomPackageManifestPath(string $path): ?string {
    $p=kicomSafeRelativePath($path);
    if($p===null)return null;
    if(!str_starts_with($p,'packages/')||strtolower(pathinfo($p,PATHINFO_EXTENSION))!=='json')return null;
    return $p;
}
function kicomReadDeployPackageManifest(string $manifestPath): array {
    $manifestPath=kicomPackageManifestPath($manifestPath);
    if($manifestPath===null)return ['ok'=>false,'code'=>'INVALID_MANIFEST_PATH'];
    $raw=kicomReadStage($manifestPath);
    if($raw===null)return ['ok'=>false,'code'=>'MANIFEST_NOT_FOUND'];
    if(strlen($raw)>KICOM_MAX_READ_BYTES)return ['ok'=>false,'code'=>'MANIFEST_TOO_LARGE'];
    $j=json_decode($raw,true);
    if(!is_array($j)||($j['format']??'')!=='kicom-deploy-package/1'||!is_array($j['files']??null))return ['ok'=>false,'code'=>'MANIFEST_INVALID'];
    $name=trim((string)($j['name']??basename($manifestPath,'.json')));
    if($name===''||strlen($name)>80)return ['ok'=>false,'code'=>'PACKAGE_NAME_INVALID'];
    $files=$j['files'];
    if(count($files)<1||count($files)>KICOM_MAX_PACKAGE_FILES)return ['ok'=>false,'code'=>'PACKAGE_FILE_COUNT_INVALID'];
    $out=[];$seen=[];$total=0;
    foreach($files as $i=>$row){
        if(!is_array($row))return ['ok'=>false,'code'=>'MANIFEST_ENTRY_INVALID','entry'=>$i+1];
        $wp=kicomSafeRelativePath((string)($row['workspace_path']??''));
        $dest=kicomSafeRelativePath((string)($row['dest_path']??''));
        if($wp===null||$dest===null)return ['ok'=>false,'code'=>'MANIFEST_PATH_INVALID','entry'=>$i+1];
        if(isset($seen[$dest]))return ['ok'=>false,'code'=>'DUPLICATE_DEST_PATH','entry'=>$i+1];
        $seen[$dest]=true;
        $content=kicomReadStage($wp);
        if($content===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND','entry'=>$i+1,'workspace_path'=>$wp];
        $v=kicomValidateContent($dest,$content);
        if($v['status']==='error')return ['ok'=>false,'code'=>'VALIDATION_FAILED','entry'=>$i+1,'dest_path'=>$dest,'validation'=>$v['message']];
        $bytes=strlen($content);$total+=$bytes;
        if($total>KICOM_MAX_PACKAGE_BYTES)return ['ok'=>false,'code'=>'PACKAGE_TOO_LARGE'];
        $out[]=['workspace_path'=>$wp,'dest_path'=>$dest,'workspace_sha256'=>hash('sha256',$content),'bytes'=>$bytes,'validation'=>$v['message']];
    }
    return ['ok'=>true,'manifest_path'=>$manifestPath,'manifest_sha256'=>hash('sha256',$raw),'package_name'=>$name,'files'=>$out,'files_count'=>count($out),'bytes'=>$total];
}
function kicomDeployPackageDryRun(string $alias,string $manifestPath): array {
    $t=kicomDeployTarget($alias,true);
    if($t===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    if((string)$t['class']==='production' && (string)$t['health_url']==='')return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_REQUIRED'];
    $m=kicomReadDeployPackageManifest($manifestPath);
    if(!$m['ok'])return $m;
    $backupTotal=0;$locked=[];
    foreach($m['files'] as $i=>$f){
        $tf=kicomTargetFile($alias,(string)$f['dest_path']);
        if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID','entry'=>$i+1];
        $full=(string)$tf['full'];
        if(is_file($full)){
            $sz=(int)filesize($full);
            if($sz>KICOM_MAX_DEPLOY_BACKUP_BYTES)return ['ok'=>false,'code'=>'TARGET_TOO_LARGE_TO_BACKUP','entry'=>$i+1];
            $backupTotal+=$sz;
            if($backupTotal>KICOM_MAX_PACKAGE_BACKUP_BYTES)return ['ok'=>false,'code'=>'PACKAGE_BACKUP_TOO_LARGE'];
        }
        $parent=dirname($full);
        $writable=is_dir($parent)?is_writable($parent):is_writable((string)$t['root']);
        if(!$writable)return ['ok'=>false,'code'=>'TARGET_NOT_WRITABLE','entry'=>$i+1];
        $locked[]=$f+['target_base_sha256'=>kicomTargetHash($alias,(string)$f['dest_path'])];
    }
    $health=kicomHealthCheck($alias);
    if((string)$t['class']==='production'&&(!$health['configured']||!$health['ok']))return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$health];
    return array_merge($m,[
        'target'=>$t['alias'],'target_class'=>$t['class'],'files'=>$locked,'backup_bytes'=>$backupTotal,
        'healthcheck_configured'=>$t['health_url']!=='','healthcheck_preflight_ok'=>$health['ok'],
        'healthcheck_http_code'=>$health['http_code'],
    ]);
}
function kicomCreateDeployPackageProposal(string $alias,string $manifestPath,string $manifestSha,string $transport): array {
    $d=kicomDeployPackageDryRun($alias,$manifestPath);
    if(!$d['ok'])return $d;
    $manifestSha=strtolower(trim($manifestSha));
    if(!preg_match('/^[a-f0-9]{64}$/',$manifestSha))return ['ok'=>false,'code'=>'INVALID_MANIFEST_SHA256'];
    if(!hash_equals((string)$d['manifest_sha256'],$manifestSha))return ['ok'=>false,'code'=>'MANIFEST_CONFLICT','current_manifest_sha256'=>$d['manifest_sha256']];
    $pr=kicomCreateProposal([
        'kind'=>'deploy_package_write','target'=>$d['target'],'target_class'=>$d['target_class'],
        'manifest_path'=>$d['manifest_path'],'manifest_sha256'=>$manifestSha,'package_name'=>$d['package_name'],
        'files'=>$d['files'],'files_count'=>$d['files_count'],'bytes'=>$d['bytes'],'sha256'=>$manifestSha,
        'healthcheck_preflight_ok'=>$d['healthcheck_preflight_ok'],'healthcheck_http_code'=>$d['healthcheck_http_code'],
        'transport'=>$transport
    ]);
    return $pr['ok']?['ok'=>true,'proposal_id'=>$pr['id']]+$d:$pr;
}
function kicomCheckDeployPackageProposal(array $p): array {
    $alias=(string)($p['target']??'');
    $target=kicomDeployTarget($alias,true);
    if($target===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    $manifestPath=kicomPackageManifestPath((string)($p['manifest_path']??''));
    if($manifestPath===null)return ['ok'=>false,'code'=>'INVALID_MANIFEST_PATH'];
    $m=kicomReadDeployPackageManifest($manifestPath);
    if(!$m['ok'])return $m;
    if(!hash_equals((string)$m['manifest_sha256'],(string)($p['manifest_sha256']??'')))return ['ok'=>false,'code'=>'MANIFEST_CONFLICT','current_manifest_sha256'=>$m['manifest_sha256']];
    $locked=$p['files']??null;
    if(!is_array($locked)||count($locked)!==count($m['files']))return ['ok'=>false,'code'=>'PACKAGE_LOCK_INVALID'];
    foreach($locked as $i=>$lf){
        if(!is_array($lf)||!isset($m['files'][$i]))return ['ok'=>false,'code'=>'PACKAGE_LOCK_INVALID'];
        $mf=$m['files'][$i];
        foreach(['workspace_path','dest_path','workspace_sha256'] as $k){
            if((string)($lf[$k]??'')!==(string)$mf[$k])return ['ok'=>false,'code'=>'PACKAGE_MANIFEST_DRIFT','entry'=>$i+1];
        }
        $curW=kicomReadStage((string)$lf['workspace_path']);
        if($curW===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND','entry'=>$i+1];
        $curWsha=hash('sha256',$curW);
        if(!hash_equals($curWsha,(string)$lf['workspace_sha256']))return ['ok'=>false,'code'=>'WORKSPACE_CONFLICT','entry'=>$i+1,'current_workspace_sha256'=>$curWsha];
        $curT=kicomTargetHash($alias,(string)$lf['dest_path']);
        if($curT===''||!hash_equals($curT,(string)($lf['target_base_sha256']??'')))return ['ok'=>false,'code'=>'TARGET_CONFLICT','entry'=>$i+1,'current_target_sha256'=>$curT];
    }
    return ['ok'=>true,'target'=>$target,'manifest'=>$m,'files'=>$locked];
}
function kicomPackageBackupEntry(string $alias,array $f): array {
    $tf=kicomTargetFile($alias,(string)$f['dest_path']);
    if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];
    $full=(string)$tf['full'];
    if(!is_file($full))return ['ok'=>true,'dest_path'=>$f['dest_path'],'before_sha256'=>'NEW','backup_content_b64'=>''];
    $raw=@file_get_contents($full);
    if($raw===false)return ['ok'=>false,'code'=>'BACKUP_READ_FAILED'];
    if(strlen($raw)>KICOM_MAX_DEPLOY_BACKUP_BYTES)return ['ok'=>false,'code'=>'TARGET_TOO_LARGE_TO_BACKUP'];
    return ['ok'=>true,'dest_path'=>$f['dest_path'],'before_sha256'=>hash('sha256',$raw),'backup_content_b64'=>base64_encode($raw)];
}
function kicomPreparePackageTemp(string $alias,string $dest,string $content,string $tx): array {
    $tf=kicomTargetFile($alias,$dest);
    if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID'];
    $full=(string)$tf['full'];$dir=dirname($full);
    if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))return ['ok'=>false,'code'=>'TARGET_DIR_FAILED'];
    $tmp=$full.'.kicom-package-'.$tx;
    if(@file_put_contents($tmp,$content,LOCK_EX)===false)return ['ok'=>false,'code'=>'TARGET_WRITE_FAILED'];
    @chmod($tmp,0644);
    return ['ok'=>true,'tmp'=>$tmp,'full'=>$full];
}
function kicomRestorePackageState(string $alias,array $entries): array {
    /* Verify every destination first and snapshot the state we would have to restore
       if the restoration itself fails halfway through. */
    $snapshots=[];
    foreach($entries as $i=>$e){
        $dest=(string)$e['dest_path'];
        $cur=kicomTargetHash($alias,$dest);
        if($cur===''||!hash_equals($cur,(string)$e['expected_current']))return ['ok'=>false,'code'=>'TARGET_CONFLICT','entry'=>$i+1,'current_target_sha256'=>$cur];
        $tf=kicomTargetFile($alias,$dest);
        if($tf===null)return ['ok'=>false,'code'=>'TARGET_PATH_INVALID','entry'=>$i+1];
        if($cur==='NEW')$snapshots[$i]=['dest_path'=>$dest,'sha256'=>'NEW','content_b64'=>''];
        else{
            $raw=@file_get_contents((string)$tf['full']);
            if($raw===false||hash('sha256',(string)$raw)!==$cur)return ['ok'=>false,'code'=>'CURRENT_STATE_READ_FAILED','entry'=>$i+1];
            $snapshots[$i]=['dest_path'=>$dest,'sha256'=>$cur,'content_b64'=>base64_encode((string)$raw)];
        }
    }
    $changed=[];$out=[];
    foreach(array_reverse($entries,true) as $i=>$e){
        $dest=(string)$e['dest_path'];$before=(string)$e['before_sha256'];$tf=kicomTargetFile($alias,$dest);
        $fail=null;$after='';
        if($tf===null)$fail=['ok'=>false,'code'=>'TARGET_PATH_INVALID','entry'=>$i+1];
        elseif($before==='NEW'){
            if(is_file((string)$tf['full'])&&!@unlink((string)$tf['full']))$fail=['ok'=>false,'code'=>'ROLLBACK_DELETE_FAILED','entry'=>$i+1];
            else $after='NEW';
        }else{
            $raw=base64_decode((string)$e['backup_content_b64'],true);
            if($raw===false||hash('sha256',$raw)!==$before)$fail=['ok'=>false,'code'=>'BACKUP_CORRUPT','entry'=>$i+1];
            else{
                $w=kicomWriteDeployTarget($alias,$dest,$raw);
                if(!$w['ok'])$fail=$w+['entry'=>$i+1];else $after=$w['sha256'];
            }
        }
        if($fail!==null){
            $recoveryOk=true;
            foreach(array_reverse($changed,true) as $j){
                $snap=$snapshots[$j];$rt=kicomTargetFile($alias,(string)$snap['dest_path']);
                if($rt===null){$recoveryOk=false;continue;}
                if($snap['sha256']==='NEW'){
                    if(is_file((string)$rt['full'])&&!@unlink((string)$rt['full']))$recoveryOk=false;
                }else{
                    $raw=base64_decode((string)$snap['content_b64'],true);
                    if($raw===false){$recoveryOk=false;continue;}
                    $w=kicomWriteDeployTarget($alias,(string)$snap['dest_path'],$raw);
                    if(!$w['ok'])$recoveryOk=false;
                }
            }
            $fail['restoration_recovery_ok']=$recoveryOk;
            return $fail;
        }
        $changed[]=$i;$out[]=['dest_path'=>$dest,'sha256'=>$after];
    }
    return ['ok'=>true,'files'=>$out];
}
function kicomApplyDeployPackageWrite(array $p): array {
    $chk=kicomCheckDeployPackageProposal($p);
    if(!$chk['ok'])return $chk;
    $alias=(string)$p['target'];$target=$chk['target'];
    if((string)$target['class']==='production'){
        $pre=kicomHealthCheck($alias);
        if(!$pre['configured']||!$pre['ok'])return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$pre];
    }
    $backups=[];$temps=[];$tx=strtolower(kicomRequestId());
    foreach($p['files'] as $i=>$f){
        $b=kicomPackageBackupEntry($alias,$f);
        if(!$b['ok'])return $b+['entry'=>$i+1];
        $backups[$i]=$b;
        $content=kicomReadStage((string)$f['workspace_path']);
        if($content===null)return ['ok'=>false,'code'=>'WORKSPACE_FILE_NOT_FOUND','entry'=>$i+1];
        $tmp=kicomPreparePackageTemp($alias,(string)$f['dest_path'],$content,$tx.'-'.$i);
        if(!$tmp['ok']){foreach($temps as $t)@unlink((string)$t['tmp']);return $tmp+['entry'=>$i+1];}
        $temps[$i]=$tmp;
    }
    $committed=[];$histFiles=[];
    foreach($p['files'] as $i=>$f){
        $tmp=$temps[$i];
        if(!@rename((string)$tmp['tmp'],(string)$tmp['full'])){
            foreach($temps as $j=>$t)if(!in_array($j,$committed,true))@unlink((string)$t['tmp']);
            $restore=[];
            foreach($committed as $j)$restore[]=$backups[$j]+['expected_current'=>(string)$p['files'][$j]['workspace_sha256']];
            $rr=$restore?kicomRestorePackageState($alias,$restore):['ok'=>true];
            $id=kicomRecordDeployment([
                'action'=>'package_deploy','target'=>$alias,'target_class'=>$target['class'],'package_name'=>$p['package_name'],
                'manifest_path'=>$p['manifest_path'],'manifest_sha256'=>$p['manifest_sha256'],'files_count'=>count($p['files']),
                'files'=>$histFiles,'status'=>$rr['ok']?'rolled_back_write_failure':'write_failure_rollback_failed','rollback_result'=>$rr
            ]);
            return ['ok'=>false,'code'=>$rr['ok']?'PACKAGE_WRITE_FAILED_ROLLED_BACK':'PACKAGE_WRITE_FAILED_ROLLBACK_FAILED','deployment_id'=>$id??'','rolled_back'=>$rr['ok']];
        }
        @chmod((string)$tmp['full'],0640);
        $committed[]=$i;$b=$backups[$i];
        $histFiles[]=[
            'workspace_path'=>$f['workspace_path'],'dest_path'=>$f['dest_path'],'workspace_sha256'=>$f['workspace_sha256'],
            'before_sha256'=>$b['before_sha256'],'after_sha256'=>$f['workspace_sha256'],'backup_content_b64'=>$b['backup_content_b64']
        ];
    }
    $health=kicomHealthCheck($alias);
    $hist=[
        'action'=>'package_deploy','target'=>$alias,'target_class'=>$target['class'],'package_name'=>$p['package_name'],
        'manifest_path'=>$p['manifest_path'],'manifest_sha256'=>$p['manifest_sha256'],'files_count'=>count($histFiles),
        'files'=>$histFiles,'health'=>$health,'status'=>$health['ok']?'deployed':'health_failed'
    ];
    if(!$health['ok']){
        $restore=[];
        foreach($histFiles as $f)$restore[]=[
            'dest_path'=>$f['dest_path'],'before_sha256'=>$f['before_sha256'],'backup_content_b64'=>$f['backup_content_b64'],
            'expected_current'=>$f['after_sha256']
        ];
        $rr=kicomRestorePackageState($alias,$restore);
        $hist['status']=$rr['ok']?'rolled_back_healthcheck':'health_failed_rollback_failed';
        $hist['rollback_result']=$rr;
        $id=kicomRecordDeployment($hist);
        return ['ok'=>false,'code'=>$rr['ok']?'HEALTHCHECK_FAILED_ROLLED_BACK':'HEALTHCHECK_FAILED_ROLLBACK_FAILED','deployment_id'=>$id??'','rolled_back'=>$rr['ok'],'health'=>$health];
    }
    $id=kicomRecordDeployment($hist);
    return ['ok'=>true,'deployment_id'=>$id??'','manifest_sha256'=>$p['manifest_sha256'],'files_count'=>count($histFiles),'health'=>$health];
}
function kicomCreateDeployPackageRollbackProposal(string $deploymentId,string $transport): array {
    $h=kicomDeployHistoryGet($deploymentId);
    if($h===null||($h['action']??'')!=='package_deploy'||($h['status']??'')!=='deployed')return ['ok'=>false,'code'=>'PACKAGE_DEPLOYMENT_NOT_FOUND'];
    $target=kicomDeployTarget((string)$h['target'],true);
    if($target===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    $files=$h['files']??null;
    if(!is_array($files)||!$files)return ['ok'=>false,'code'=>'PACKAGE_HISTORY_INVALID'];
    foreach($files as $i=>$f){
        $cur=kicomTargetHash((string)$h['target'],(string)$f['dest_path']);
        if($cur===''||!hash_equals($cur,(string)$f['after_sha256']))return ['ok'=>false,'code'=>'TARGET_CONFLICT','entry'=>$i+1,'current_target_sha256'=>$cur];
    }
    $pr=kicomCreateProposal([
        'kind'=>'deploy_package_rollback','target'=>$h['target'],'target_class'=>$target['class'],
        'source_deployment'=>$deploymentId,'package_name'=>$h['package_name']??'package',
        'manifest_sha256'=>$h['manifest_sha256']??'','files_count'=>count($files),'files'=>$files,
        'bytes'=>0,'sha256'=>(string)($h['manifest_sha256']??''),'transport'=>$transport
    ]);
    return $pr['ok']?[
        'ok'=>true,'proposal_id'=>$pr['id'],'target'=>$h['target'],'target_class'=>$target['class'],
        'source_deployment'=>$deploymentId,'package_name'=>$h['package_name']??'package','files_count'=>count($files)
    ]:$pr;
}
function kicomApplyDeployPackageRollback(array $p): array {
    $source=kicomDeployHistoryGet((string)($p['source_deployment']??''));
    if($source===null||($source['action']??'')!=='package_deploy')return ['ok'=>false,'code'=>'PACKAGE_DEPLOYMENT_NOT_FOUND'];
    $alias=(string)$source['target'];$target=kicomDeployTarget($alias,true);
    if($target===null)return ['ok'=>false,'code'=>'TARGET_UNAVAILABLE'];
    if((string)$target['class']==='production'){
        $pre=kicomHealthCheck($alias);
        if(!$pre['configured']||!$pre['ok'])return ['ok'=>false,'code'=>'PRODUCTION_HEALTHCHECK_FAILED','health'=>$pre];
    }
    $files=$source['files']??null;
    if(!is_array($files)||!$files)return ['ok'=>false,'code'=>'PACKAGE_HISTORY_INVALID'];
    $restore=[];$currentBackups=[];
    foreach($files as $i=>$f){
        $cur=kicomTargetHash($alias,(string)$f['dest_path']);
        if($cur===''||!hash_equals($cur,(string)$f['after_sha256']))return ['ok'=>false,'code'=>'TARGET_CONFLICT','entry'=>$i+1,'current_target_sha256'=>$cur];
        $tf=kicomTargetFile($alias,(string)$f['dest_path']);
        $raw=($tf!==null&&is_file((string)$tf['full']))?@file_get_contents((string)$tf['full']):false;
        $currentBackups[]=['dest_path'=>$f['dest_path'],'before_sha256'=>$cur,'backup_content_b64'=>$raw===false?'':base64_encode((string)$raw),'expected_current'=>(string)$f['before_sha256']];
        $restore[]=['dest_path'=>$f['dest_path'],'before_sha256'=>$f['before_sha256'],'backup_content_b64'=>$f['backup_content_b64'],'expected_current'=>$cur];
    }
    $rr=kicomRestorePackageState($alias,$restore);
    if(!$rr['ok'])return $rr;
    $health=kicomHealthCheck($alias);
    if(!$health['ok']){
        $reapply=kicomRestorePackageState($alias,$currentBackups);
        $id=kicomRecordDeployment([
            'action'=>'package_rollback','target'=>$alias,'target_class'=>$target['class'],'source_deployment'=>$source['id'],
            'package_name'=>$source['package_name']??'package','manifest_sha256'=>$source['manifest_sha256']??'',
            'files_count'=>count($files),'status'=>$reapply['ok']?'rollback_health_failed_reapplied':'rollback_health_failed_reapply_failed',
            'health'=>$health,'rollback_result'=>$rr,'reapply_result'=>$reapply
        ]);
        return ['ok'=>false,'code'=>$reapply['ok']?'ROLLBACK_HEALTHCHECK_FAILED_REAPPLIED':'ROLLBACK_HEALTHCHECK_FAILED_REAPPLY_FAILED','deployment_id'=>$id??'','rolled_back'=>$reapply['ok']];
    }
    $histFiles=[];
    foreach($files as $f)$histFiles[]=['dest_path'=>$f['dest_path'],'before_sha256'=>$f['after_sha256'],'after_sha256'=>$f['before_sha256']];
    $id=kicomRecordDeployment([
        'action'=>'package_rollback','target'=>$alias,'target_class'=>$target['class'],'source_deployment'=>$source['id'],
        'package_name'=>$source['package_name']??'package','manifest_sha256'=>$source['manifest_sha256']??'',
        'files_count'=>count($files),'files'=>$histFiles,'status'=>'rolled_back','health'=>$health
    ]);
    return ['ok'=>true,'deployment_id'=>$id??'','files_count'=>count($files),'health'=>$health];
}
function kicomDeployPackageProposalConflict(array $p): bool {
    if((string)($p['kind']??'')==='deploy_package_write')return !kicomCheckDeployPackageProposal($p)['ok'];
    if((string)($p['kind']??'')==='deploy_package_rollback'){
        $h=kicomDeployHistoryGet((string)($p['source_deployment']??''));
        if($h===null||!is_array($h['files']??null))return true;
        foreach($h['files'] as $f){
            if(kicomTargetHash((string)$h['target'],(string)$f['dest_path'])!==(string)$f['after_sha256'])return true;
        }
        return false;
    }
    return false;
}


/* ---- KiCom 0.9.2 human control plane + redundant update channels -------- */
function kicomRandomSecret(int $bytes=24): string {
    try { return bin2hex(random_bytes($bytes)); }
    catch(Throwable $e){ return hash('sha256',uniqid('',true).'|'.microtime(true)); }
}
function kicomUpdateChannelsDefaults(): array {
    return [
        'schema'=>1,
        'auto_install_green'=>true,
        'pull'=>[
            'enabled'=>true,
            'feeds'=>[
                ['name'=>'primary','enabled'=>true,'url'=>'https://update.rurtalbahn.info/kicom/channel.json'],
                ['name'=>'mirror','enabled'=>false,'url'=>'']
            ]
        ],
        'push'=>[
            'enabled'=>true,
            'key_plain'=>kicomRandomSecret(24),
            'created_at'=>gmdate('c')
        ],
        'agent'=>[
            'key_plain'=>kicomRandomSecret(24),
            'created_at'=>gmdate('c')
        ]
    ];
}
function kicomUpdateChannelsLoad(): array {
    $f=kicomUpdateChannelsFile();
    if(!is_file($f)){
        $cfg=kicomUpdateChannelsDefaults();
        $j=json_encode($cfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if($j!==false){@file_put_contents($f,$j."\n",LOCK_EX);@chmod($f,0600);}
        return $cfg;
    }
    $cfg=json_decode((string)@file_get_contents($f),true);
    if(!is_array($cfg))$cfg=kicomUpdateChannelsDefaults();
    $cfg['schema']=1;
    $cfg['auto_install_green']=array_key_exists('auto_install_green',$cfg)?(bool)$cfg['auto_install_green']:true;
    if(!is_array($cfg['pull']??null))$cfg['pull']=['enabled'=>true,'feeds'=>[]];
    if(!is_array($cfg['pull']['feeds']??null))$cfg['pull']['feeds']=[];
    if(!is_array($cfg['push']??null))$cfg['push']=['enabled'=>true];
    if(!is_string($cfg['push']['key_plain']??null)||strlen((string)$cfg['push']['key_plain'])<32)$cfg['push']['key_plain']=kicomRandomSecret(24);
    if(!is_array($cfg['agent']??null))$cfg['agent']=[];
    if(!is_string($cfg['agent']['key_plain']??null)||strlen((string)$cfg['agent']['key_plain'])<32)$cfg['agent']['key_plain']=kicomRandomSecret(24);
    return $cfg;
}
function kicomUpdateChannelsSave(array $cfg): bool {
    $feeds=[];
    foreach(($cfg['pull']['feeds']??[]) as $f){
        if(!is_array($f))continue;
        $name=preg_replace('/[^a-z0-9_-]/i','',(string)($f['name']??'feed'))?:'feed';
        $url=trim((string)($f['url']??''));
        $enabled=!empty($f['enabled']);
        if($url!==''&&!kicomUpdateFeedUrlAllowed($url))return false;
        $feeds[]=['name'=>substr($name,0,32),'enabled'=>$enabled,'url'=>$url];
        if(count($feeds)>=4)break;
    }
    $current=kicomUpdateChannelsLoad();
    $clean=[
        'schema'=>1,
        'auto_install_green'=>(bool)($cfg['auto_install_green']??false),
        'pull'=>['enabled'=>(bool)($cfg['pull']['enabled']??false),'feeds'=>$feeds],
        'push'=>[
            'enabled'=>(bool)($cfg['push']['enabled']??false),
            'key_plain'=>(string)($cfg['push']['key_plain']??$current['push']['key_plain']),
            'created_at'=>(string)($current['push']['created_at']??gmdate('c'))
        ],
        'agent'=>[
            'key_plain'=>(string)($cfg['agent']['key_plain']??$current['agent']['key_plain']),
            'created_at'=>(string)($current['agent']['created_at']??gmdate('c'))
        ]
    ];
    $j=json_encode($clean,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    return $j!==false&&@file_put_contents(kicomUpdateChannelsFile(),$j."\n",LOCK_EX)!==false&&@chmod(kicomUpdateChannelsFile(),0600);
}
function kicomUpdateFeedUrlAllowed(string $url): bool {
    $p=parse_url($url);
    if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https')return false;
    $host=strtolower((string)($p['host']??''));
    if($host===''||isset($p['user'])||isset($p['pass']))return false;
    if(filter_var($host,FILTER_VALIDATE_IP))return false;
    $port=(int)($p['port']??443);if($port!==443)return false;
    return true;
}
function kicomUpdatePackageUrlAllowed(string $url,string $feedUrl): bool {
    if(!kicomUpdateFeedUrlAllowed($url))return false;
    $uh=strtolower((string)parse_url($url,PHP_URL_HOST));
    $fh=strtolower((string)parse_url($feedUrl,PHP_URL_HOST));
    return $uh!==''&&hash_equals($fh,$uh);
}
function kicomUpdateChannelState(): array {
    $r=is_file(kicomUpdateChannelStateFile())?json_decode((string)@file_get_contents(kicomUpdateChannelStateFile()),true):[];
    return is_array($r)?$r:[];
}
function kicomUpdateChannelStateWrite(array $r): void {
    $j=json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($j!==false){@file_put_contents(kicomUpdateChannelStateFile(),$j."\n",LOCK_EX);@chmod(kicomUpdateChannelStateFile(),0600);}
}
function kicomUpdateChannelsPublicStatus(): array {
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
}
function kicomUpdatePushKey(): string { $c=kicomUpdateChannelsLoad();return (string)($c['push']['key_plain']??''); }
function kicomUpdateAgentKey(): string { $c=kicomUpdateChannelsLoad();return (string)($c['agent']['key_plain']??''); }
function kicomUpdateRotatePushKey(): string {
    $c=kicomUpdateChannelsLoad();$c['push']['key_plain']=kicomRandomSecret(24);$c['push']['created_at']=gmdate('c');kicomUpdateChannelsSave($c);return (string)$c['push']['key_plain'];
}
function kicomUpdateRotateAgentKey(): string {
    $c=kicomUpdateChannelsLoad();$c['agent']['key_plain']=kicomRandomSecret(24);$c['agent']['created_at']=gmdate('c');kicomUpdateChannelsSave($c);return (string)$c['agent']['key_plain'];
}
function kicomUpdatePushAuth(string $provided): bool {
    $cfg=kicomUpdateChannelsLoad();if(empty($cfg['push']['enabled']))return false;
    $key=(string)($cfg['push']['key_plain']??'');return $key!==''&&$provided!==''&&hash_equals($key,$provided);
}
function kicomUpdateAgentAuth(string $provided): bool {
    $key=kicomUpdateAgentKey();return $key!==''&&$provided!==''&&hash_equals($key,$provided);
}
function kicomUpdatePushRateAllowed(string $fingerprint): bool {
    $now=time();$f=kicomUpdatePushRateFile();$r=is_file($f)?json_decode((string)@file_get_contents($f),true):[];
    if(!is_array($r))$r=[];$bucket=(int)floor($now/60);$key=hash('sha256',$fingerprint);
    $row=$r[$key]??['bucket'=>$bucket,'count'=>0];
    if((int)($row['bucket']??-1)!==$bucket)$row=['bucket'=>$bucket,'count'=>0];
    $row['count']=(int)$row['count']+1;$r=[$key=>$row];
    $j=json_encode($r);if($j!==false){@file_put_contents($f,$j,LOCK_EX);@chmod($f,0600);}
    return (int)$row['count']<=KICOM_UPDATE_PUSH_MAX_ATTEMPTS_PER_MINUTE;
}
function kicomUpdateHttpGet(string $url,int $maxBytes): array {
    if(!kicomUpdateFeedUrlAllowed($url))return ['ok'=>false,'code'=>'URL_NOT_ALLOWED'];
    if(function_exists('curl_init')){
        $buf='';$tooLarge=false;$ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>KICOM_HEALTH_TIMEOUT,CURLOPT_TIMEOUT=>max(8,KICOM_HEALTH_TIMEOUT),
            CURLOPT_USERAGENT=>'KiCom-UpdateChannel/'.KICOM_VERSION,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>function($ch,$data)use(&$buf,&$tooLarge,$maxBytes){
                if(strlen($buf)+strlen($data)>$maxBytes){$tooLarge=true;return 0;}
                $buf.=$data;return strlen($data);
            }
        ]);
        $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=(string)curl_error($ch);curl_close($ch);
        if($tooLarge)return ['ok'=>false,'code'=>'REMOTE_TOO_LARGE'];
        if($ok===false||$code<200||$code>=300)return ['ok'=>false,'code'=>'REMOTE_HTTP_FAILED','http_code'=>$code,'error'=>$err];
        return ['ok'=>true,'body'=>$buf,'http_code'=>$code];
    }
    $ctx=stream_context_create(['http'=>['timeout'=>max(8,KICOM_HEALTH_TIMEOUT),'follow_location'=>0,'user_agent'=>'KiCom-UpdateChannel/'.KICOM_VERSION]]);
    $body=@file_get_contents($url,false,$ctx,0,$maxBytes+1);
    if($body===false)return ['ok'=>false,'code'=>'REMOTE_HTTP_FAILED'];
    if(strlen($body)>$maxBytes)return ['ok'=>false,'code'=>'REMOTE_TOO_LARGE'];
    return ['ok'=>true,'body'=>$body,'http_code'=>200];
}
function kicomUpdateDownloadPackage(string $url,string $feedUrl,string $expectedSha): array {
    if(!kicomUpdatePackageUrlAllowed($url,$feedUrl))return ['ok'=>false,'code'=>'PACKAGE_URL_NOT_ALLOWED'];
    if(!preg_match('/^[a-f0-9]{64}$/',$expectedSha))return ['ok'=>false,'code'=>'PACKAGE_SHA_INVALID'];
    $r=kicomUpdateHttpGet($url,KICOM_MAX_SELF_UPDATE_ZIP_BYTES);
    if(!$r['ok'])return $r;
    $body=(string)$r['body'];$sha=hash('sha256',$body);if(!hash_equals($expectedSha,$sha))return ['ok'=>false,'code'=>'PACKAGE_SHA_MISMATCH'];
    $tmp=kicomTempDir().'/pull-'.substr($sha,0,20).'.zip';
    if(@file_put_contents($tmp,$body,LOCK_EX)===false)return ['ok'=>false,'code'=>'PACKAGE_TEMP_WRITE_FAILED'];
    @chmod($tmp,0600);return ['ok'=>true,'path'=>$tmp,'sha256'=>$sha,'bytes'=>strlen($body)];
}
function kicomUpdateFeedParse(string $raw,string $feedUrl): array {
    $j=json_decode($raw,true);if(!is_array($j)||(int)($j['schema']??0)!==1||strtolower((string)($j['product']??''))!=='kicom')return ['ok'=>false,'code'=>'FEED_INVALID'];
    $rows=[];
    $releases=$j['releases']??(isset($j['latest'])?[$j['latest']]:[]);
    if(!is_array($releases))return ['ok'=>false,'code'=>'FEED_RELEASES_INVALID'];
    foreach($releases as $x){
        if(!is_array($x))continue;$v=(string)($x['version']??'');$u=(string)($x['url']??'');$s=strtolower((string)($x['sha256']??''));
        if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$v)||!preg_match('/^[a-f0-9]{64}$/',$s)||!kicomUpdatePackageUrlAllowed($u,$feedUrl))continue;
        if(version_compare($v,KICOM_VERSION,'>'))$rows[]=['version'=>$v,'url'=>$u,'sha256'=>$s,'published_at'=>(string)($x['published_at']??'')];
    }
    usort($rows,fn($a,$b)=>version_compare((string)$b['version'],(string)$a['version']));
    return ['ok'=>true,'releases'=>$rows];
}
/* ---- end channel primitives --------------------------------------------- */

/* ---- KiCom self-update controller (0.8) ---------------------------------- */
function kicomSelfUpdateSupported(): bool {
    return class_exists('ZipArchive') || class_exists('PharData');
}
function kicomSafeUpdatePath(string $path): ?string {
    $path=str_replace('\\','/',$path);
    $path=preg_replace('~^\./+~','',$path)??$path;
    $path=ltrim(trim($path),'/');
    if($path===''||strlen($path)>220||str_contains($path,"\0"))return null;
    if(!preg_match('~^[A-Za-z0-9_./-]+$~',$path))return null;
    $parts=explode('/',$path);
    foreach($parts as $seg){
        if($seg===''||$seg==='.'||$seg==='..')return null;
        if(str_starts_with($seg,'.')&&$seg!=='.htaccess')return null;
    }
    return $path;
}
function kicomSelfUpdateInstallPathAllowed(string $path): bool {
    $root=['.htaccess','README.md','admin.php','api.php','index.php','lib.php','living.php','recovery.php','guardian.php','robots.txt','MANIFEST.sha256'];
    if(in_array($path,$root,true))return true;
    if(in_array($path,['var/.htaccess','stage/.htaccess'],true))return true;
    if(str_starts_with($path,'memory/'))return basename($path)==='.htaccess'||strtolower(pathinfo($path,PATHINFO_EXTENSION))==='kcl';
    if(str_starts_with($path,'genome/'))return basename($path)==='.htaccess'||in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['json','sig','txt'],true);
    if(str_starts_with($path,'assets/'))return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['css','js','json','svg','png','jpg','jpeg','webp','ico','txt'],true);
    return false;
}
function kicomSelfUpdateManifestParse(string $raw): array {
    $rows=[];
    foreach(preg_split('/\r?\n/',$raw)?:[] as $line){
        $line=trim($line);if($line==='')continue;
        if(!preg_match('/^([a-f0-9]{64})\s+(.+)$/i',$line,$m))return ['ok'=>false,'code'=>'MANIFEST_INVALID'];
        $path=kicomSafeUpdatePath(trim($m[2]));
        if($path===null||isset($rows[$path]))return ['ok'=>false,'code'=>'MANIFEST_PATH_INVALID'];
        $rows[$path]=strtolower($m[1]);
    }
    foreach(['.htaccess','lib.php','index.php','api.php','admin.php','living.php','recovery.php','guardian.php','robots.txt','genome/genome.json'] as $required)if(!isset($rows[$required]))return ['ok'=>false,'code'=>'MANIFEST_REQUIRED_FILE_MISSING','path'=>$required];
    return ['ok'=>true,'files'=>$rows];
}
function kicomSelfUpdateZipInspect(string $zipPath): array {
    if(!kicomSelfUpdateSupported())return ['ok'=>false,'code'=>'ZIP_READER_UNAVAILABLE'];
    if(!is_file($zipPath))return ['ok'=>false,'code'=>'PACKAGE_NOT_FOUND'];
    $size=(int)@filesize($zipPath);if($size<=0||$size>KICOM_MAX_SELF_UPDATE_ZIP_BYTES)return ['ok'=>false,'code'=>'PACKAGE_SIZE_INVALID'];
    $entries=[];$total=0;
    if(class_exists('ZipArchive')){
        $zip=new ZipArchive();$opened=$zip->open($zipPath,ZipArchive::RDONLY);
        if($opened!==true)return ['ok'=>false,'code'=>'ZIP_OPEN_FAILED'];
        try{
            if($zip->numFiles<1||$zip->numFiles>KICOM_MAX_SELF_UPDATE_FILES)return ['ok'=>false,'code'=>'ZIP_FILE_COUNT_INVALID'];
            for($i=0;$i<$zip->numFiles;$i++){
                $st=$zip->statIndex($i,ZipArchive::FL_UNCHANGED);if(!is_array($st))return ['ok'=>false,'code'=>'ZIP_ENTRY_INVALID'];
                $name=(string)($st['name']??'');if(str_ends_with($name,'/'))continue;
                $clean=kicomSafeUpdatePath($name);if($clean===null||isset($entries[$clean]))return ['ok'=>false,'code'=>'ZIP_PATH_INVALID'];
                if(method_exists($zip,'getExternalAttributesIndex')){
                    $opsys=0;$attr=0;if($zip->getExternalAttributesIndex($i,$opsys,$attr)){$mode=($attr>>16)&0170000;if($mode===0120000)return ['ok'=>false,'code'=>'ZIP_SYMLINK_FORBIDDEN','path'=>$clean];}
                }
                $usize=(int)($st['size']??0);$total+=$usize;if($total>KICOM_MAX_SELF_UPDATE_UNCOMPRESSED_BYTES)return ['ok'=>false,'code'=>'ZIP_UNCOMPRESSED_TOO_LARGE'];
                $raw=$zip->getFromIndex($i);if($raw===false)return ['ok'=>false,'code'=>'ZIP_READ_FAILED','path'=>$clean];
                $entries[$clean]=['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'content'=>$raw];
            }
        } finally {$zip->close();}
    } else {
        try{
            $real=realpath($zipPath);if($real===false)return ['ok'=>false,'code'=>'ZIP_OPEN_FAILED'];
            $phar=new PharData($real);$prefix='phar://'.str_replace('\\','/',$real).'/';$count=0;
            foreach(new RecursiveIteratorIterator($phar,RecursiveIteratorIterator::LEAVES_ONLY) as $file){
                if(!($file instanceof PharFileInfo))continue;$count++;if($count>KICOM_MAX_SELF_UPDATE_FILES)return ['ok'=>false,'code'=>'ZIP_FILE_COUNT_INVALID'];
                if($file->isLink())return ['ok'=>false,'code'=>'ZIP_SYMLINK_FORBIDDEN'];
                $pathname=str_replace('\\','/',$file->getPathname());if(!str_starts_with($pathname,$prefix))return ['ok'=>false,'code'=>'ZIP_PATH_INVALID'];
                $clean=kicomSafeUpdatePath(substr($pathname,strlen($prefix)));if($clean===null||isset($entries[$clean]))return ['ok'=>false,'code'=>'ZIP_PATH_INVALID'];
                $usize=(int)$file->getSize();$total+=$usize;if($total>KICOM_MAX_SELF_UPDATE_UNCOMPRESSED_BYTES)return ['ok'=>false,'code'=>'ZIP_UNCOMPRESSED_TOO_LARGE'];
                $raw=@file_get_contents($pathname);if($raw===false)return ['ok'=>false,'code'=>'ZIP_READ_FAILED','path'=>$clean];
                $entries[$clean]=['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'content'=>$raw];
            }
            if($count<1)return ['ok'=>false,'code'=>'ZIP_FILE_COUNT_INVALID'];
        } catch(Throwable $e){return ['ok'=>false,'code'=>'ZIP_OPEN_FAILED'];}
    }
    if(!isset($entries['MANIFEST.sha256']))return ['ok'=>false,'code'=>'MANIFEST_MISSING'];
    $manifestRaw=(string)$entries['MANIFEST.sha256']['content'];$mp=kicomSelfUpdateManifestParse($manifestRaw);if(!$mp['ok'])return $mp;$manifest=$mp['files'];
    foreach($entries as $path=>$e){if($path==='MANIFEST.sha256')continue;if(!isset($manifest[$path]))return ['ok'=>false,'code'=>'UNMANIFESTED_FILE','path'=>$path];}
    foreach($manifest as $path=>$sha){if(!isset($entries[$path]))return ['ok'=>false,'code'=>'MANIFEST_FILE_MISSING','path'=>$path];if(!hash_equals($sha,(string)$entries[$path]['sha256']))return ['ok'=>false,'code'=>'MANIFEST_HASH_MISMATCH','path'=>$path];}
    $lib=(string)$entries['lib.php']['content'];if(!preg_match("/const\\s+KICOM_VERSION\\s*=\\s*'([0-9]+\\.[0-9]+\\.[0-9]+)'\\s*;/",$lib,$m))return ['ok'=>false,'code'=>'VERSION_NOT_FOUND'];$version=$m[1];
    $install=[];$preserved=[];foreach(array_keys($manifest) as $path){$stateSentinel=in_array($path,['var/.htaccess','stage/.htaccess'],true);if((str_starts_with($path,'var/')||str_starts_with($path,'stage/'))&&!$stateSentinel){$preserved[]=$path;continue;}if(!kicomSelfUpdateInstallPathAllowed($path))return ['ok'=>false,'code'=>'UPDATE_PATH_NOT_ALLOWLISTED','path'=>$path];$install[$path]=$entries[$path];}
    foreach($install as $path=>$e){
        if(strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='php')continue;
        try{token_get_all((string)$e['content'],TOKEN_PARSE);}catch(ParseError $pe){return ['ok'=>false,'code'=>'PHP_SYNTAX_INVALID','path'=>$path];}
    }
    $genomeRaw=(string)($entries['genome/genome.json']['content']??'');
    $genome=json_decode($genomeRaw,true);
    if(!is_array($genome))return ['ok'=>false,'code'=>'GENOME_JSON_INVALID'];
    if(!hash_equals($version,(string)($genome['version']??'')))return ['ok'=>false,'code'=>'GENOME_VERSION_MISMATCH'];
    $gv=kicomGenomeValidateArray($genome,$entries);if(!$gv['ok'])return $gv;
    $currentGenome=kicomGenomeCurrent();
    if(is_array($currentGenome)){
        if(!hash_equals((string)($currentGenome['id']??''),(string)($genome['parent']??'')))return ['ok'=>false,'code'=>'GENOME_PARENT_MISMATCH'];
        if((int)($genome['generation']??0)!==(int)($currentGenome['generation']??0)+1)return ['ok'=>false,'code'=>'GENOME_GENERATION_INVALID'];
    }
    $currentRecovery=is_file(kicomBaseDir().'/recovery.php')?(hash_file('sha256',kicomBaseDir().'/recovery.php')?:''):'';
    $kernelChanged=!hash_equals($currentRecovery,(string)($entries['recovery.php']['sha256']??''));
    if(is_array($currentGenome)){
        $oldKr=(int)($currentGenome['kernel_revision']??0);$newKr=(int)($genome['kernel_revision']??0);
        if($kernelChanged&&$newKr<=$oldKr)return ['ok'=>false,'code'=>'KERNEL_REVISION_NOT_INCREMENTED'];
        if(!$kernelChanged&&$newKr!==$oldKr)return ['ok'=>false,'code'=>'KERNEL_REVISION_CHANGED_WITHOUT_KERNEL'];
    }
    $install['MANIFEST.sha256']=$entries['MANIFEST.sha256'];
    return ['ok'=>true,'version'=>$version,'zip_sha256'=>hash_file('sha256',$zipPath)?:'', 'zip_bytes'=>$size,'files_count'=>count($entries),'install_files'=>$install,'preserved_files'=>$preserved,'manifest_files'=>$manifest,'manifest_sha256'=>hash('sha256',$manifestRaw),'genome'=>$genome,'genome_sha256'=>hash('sha256',$genomeRaw),'entries'=>$entries,'kernel_update'=>$kernelChanged];
}

function kicomSelfUpdateNormalizedLib(string $raw): string {
    return preg_replace("/const\s+KICOM_VERSION\s*=\s*'[0-9]+\.[0-9]+\.[0-9]+'\s*;/","const KICOM_VERSION = '__VERSION__';",$raw,1)??$raw;
}
function kicomSelfUpdateChangedPaths(array $check): array {
    $changed=[];
    foreach(($check['install_files']??[]) as $path=>$e){
        if($path==='MANIFEST.sha256'){$changed[]=$path;continue;}
        $full=kicomBaseDir().'/'.$path;
        $cur=is_file($full)?(hash_file('sha256',$full)?:''):'NEW';
        if(!hash_equals((string)($e['sha256']??''),$cur))$changed[]=$path;
    }
    return $changed;
}
function kicomSelfUpdateRiskClass(array $check): array {
    if(empty($check['ok']))return ['class'=>'red','reasons'=>['package-not-verified'],'changed'=>[]];
    $changed=kicomSelfUpdateChangedPaths($check);$reasons=[];$class='green';
    $current=kicomGenomeCurrent();$new=$check['genome']??null;
    if(!is_array($new)||!is_array($current))return ['class'=>'red','reasons'=>['genome-unavailable'],'changed'=>$changed];

    if(!empty($check['kernel_update'])){$class='red';$reasons[]='recovery-kernel-change';}
    foreach(['invariants','healthchecks','mutable_paths'] as $field){
        $a=$current[$field]??[];$b=$new[$field]??[];
        if(json_encode($a)!==json_encode($b)){$class='red';$reasons[]='genome-'.$field.'-change';}
    }
    if((int)($current['kernel_revision']??0)!==(int)($new['kernel_revision']??0)){$class='red';$reasons[]='kernel-revision-change';}

    $curComponents=[];foreach(($current['components']??[]) as $c)if(is_array($c)&&isset($c['path']))$curComponents[(string)$c['path']]=$c;
    $newComponents=[];foreach(($new['components']??[]) as $c)if(is_array($c)&&isset($c['path']))$newComponents[(string)$c['path']]=$c;
    $curKeys=array_keys($curComponents);$newKeys=array_keys($newComponents);sort($curKeys);sort($newKeys);
    if($curKeys!==$newKeys){
        $class='red';$reasons[]='genome-component-set-change';
    } else {
        foreach($newComponents as $path=>$c){
            $old=$curComponents[$path]??[];
            if((bool)($old['auto_heal']??false)!==(bool)($c['auto_heal']??false)||($old['role']??'')!==($c['role']??'')){
                $class='red';$reasons[]='component-policy-change:'.$path;break;
            }
        }
    }

    $hardRed=['recovery.php','guardian.php','api.php','index.php','.htaccess','robots.txt','genome/.htaccess','memory/.htaccess','stage/.htaccess','var/.htaccess'];
    foreach($changed as $path)if(in_array($path,$hardRed,true)&&$path!=='MANIFEST.sha256'){
        $class='red';$reasons[]='security-boundary-change:'.$path;
    }

    if(in_array('lib.php',$changed,true)){
        $cur=@file_get_contents(kicomBaseDir().'/lib.php');$newLib=(string)($check['entries']['lib.php']['content']??'');
        if($cur===false||kicomSelfUpdateNormalizedLib((string)$cur)!==kicomSelfUpdateNormalizedLib($newLib)){
            if($class!=='red')$class='yellow';$reasons[]='core-library-change';
        } else $reasons[]='version-metadata-only';
    }

    foreach($changed as $path){
        if(in_array($path,['MANIFEST.sha256','genome/genome.json','lib.php','README.md'],true)||str_starts_with($path,'assets/'))continue;
        if(in_array($path,['admin.php','living.php'],true)||str_starts_with($path,'memory/')){
            if($class==='green')$class='yellow';$reasons[]='reviewed-code-or-memory-change:'.$path;continue;
        }
        if(str_ends_with(strtolower($path),'.php')&&$class==='green'){$class='yellow';$reasons[]='php-change:'.$path;}
    }
    if($class==='green'&&!$reasons)$reasons[]='presentation-or-metadata-only';
    return ['class'=>$class,'reasons'=>array_values(array_unique($reasons)),'changed'=>$changed];
}
function kicomStageSelfUpdatePackage(string $sourcePath,string $originalName='update.zip',string $source='admin_upload'): array {
    if(!kicomEnsureStorage())return ['ok'=>false,'code'=>'STORAGE_UNAVAILABLE'];
    $check=kicomSelfUpdateZipInspect($sourcePath);if(!$check['ok'])return $check;
    $version=(string)$check['version'];
    if(version_compare($version,KICOM_VERSION,'<='))return ['ok'=>false,'code'=>'VERSION_NOT_NEWER','current_version'=>KICOM_VERSION,'package_version'=>$version];
    $risk=kicomSelfUpdateRiskClass($check);
    $sha=(string)$check['zip_sha256'];$dest=kicomSelfUpdatePackagesDir().'/'.$sha.'.zip';
    if(!is_file($dest)&&!@copy($sourcePath,$dest))return ['ok'=>false,'code'=>'PACKAGE_STORE_FAILED'];@chmod($dest,0600);
    $pending=[
        'package_file'=>basename($dest),'original_name'=>basename($originalName),
        'from_version'=>KICOM_VERSION,'to_version'=>$version,'zip_sha256'=>$sha,
        'manifest_sha256'=>$check['manifest_sha256'],'genome_id'=>(string)($check['genome']['id']??''),
        'genome_sha256'=>(string)($check['genome_sha256']??''),'kernel_update'=>(bool)($check['kernel_update']??false),
        'zip_bytes'=>$check['zip_bytes'],'files_count'=>$check['files_count'],
        'install_files_count'=>count($check['install_files']),'preserved_state_files'=>count($check['preserved_files']),
        'source'=>substr(preg_replace('/[^A-Za-z0-9_.:-]/','',$source)??'unknown',0,80),
        'risk_class'=>$risk['class'],'risk_reasons'=>$risk['reasons'],'changed_paths'=>$risk['changed'],
        'created_at'=>gmdate('c')
    ];
    $json=json_encode($pending,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($json===false||@file_put_contents(kicomSelfUpdatePendingFile(),$json,LOCK_EX)===false)return ['ok'=>false,'code'=>'PENDING_WRITE_FAILED'];@chmod(kicomSelfUpdatePendingFile(),0600);
    kicomLivingEvent('update_received','info',['source'=>$pending['source'],'to_version'=>$version,'risk_class'=>$risk['class'],'sha256'=>$sha]);
    return ['ok'=>true]+$pending;
}
function kicomReceiveSelfUpdatePackage(string $sourcePath,string $originalName,string $source,bool $allowAuto=true): array {
    $r=kicomStageSelfUpdatePackage($sourcePath,$originalName,$source);if(!$r['ok'])return $r;
    $cfg=kicomUpdateChannelsLoad();
    if($allowAuto&&($r['risk_class']??'')==='green'&&!empty($cfg['auto_install_green'])){
        $pending=kicomSelfUpdatePending();if($pending===null)return ['ok'=>false,'code'=>'PENDING_LOST'];
        $apply=kicomApplySelfUpdate($pending);
        return ['ok'=>(bool)($apply['ok']??false),'code'=>($apply['ok']??false)?'AUTO_INSTALLED_GREEN':($apply['code']??'AUTO_INSTALL_FAILED'),'staged'=>$r,'install'=>$apply,'risk_class'=>'green'];
    }
    return ['ok'=>true,'code'=>'STAGED_DECISION_REQUIRED','risk_class'=>(string)$r['risk_class'],'staged'=>$r];
}
function kicomUpdatePullCheck(bool $allowAuto=true,bool $force=false): array {
    if(!kicomEnsureStorage())return ['ok'=>false,'code'=>'STORAGE_UNAVAILABLE'];
    $cfg=kicomUpdateChannelsLoad();if(empty($cfg['pull']['enabled']))return ['ok'=>true,'code'=>'PULL_DISABLED'];
    $state=kicomUpdateChannelState();$last=(int)($state['last_check_epoch']??0);
    if(!$force&&$last>0&&(time()-$last)<KICOM_UPDATE_CHANNEL_MIN_INTERVAL)return ['ok'=>true,'code'=>'PULL_THROTTLED','last_check_at'=>$state['last_check_at']??''];
    $candidates=[];$errors=[];$feedChecks=[];
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
        $evo=kicomEvolutionAutonomousTick();
        $code=(string)($evo['code']??'');
        $now+=['last_code'=>$code==='AUTO_INSTALLED_GREEN'?'EVOLUTION_AUTO_INSTALLED_GREEN':($errors?'NO_RELEASE_FEED_ERRORS':'NO_UPDATE'),'last_source'=>$code==='AUTO_INSTALLED_GREEN'?'evolution':'pull','errors'=>$errors,'evolution_code'=>$code];kicomUpdateChannelStateWrite($now);
        if($code==='AUTO_INSTALLED_GREEN')return ['ok'=>true,'code'=>'EVOLUTION_AUTO_INSTALLED_GREEN','errors'=>$errors,'feed_checks'=>$feedChecks,'evolution'=>$evo];
        return ['ok'=>true,'code'=>$now['last_code'],'errors'=>$errors,'feed_checks'=>$feedChecks,'evolution'=>$evo];
    }
    usort($candidates,fn($a,$b)=>version_compare((string)$b['version'],(string)$a['version']));
    $best=$candidates[0];$same=array_values(array_filter($candidates,fn($x)=>($x['version']??'')===$best['version']));
    $hashes=array_values(array_unique(array_map(fn($x)=>(string)$x['sha256'],$same)));
    if(count($hashes)>1){
        $now+=['last_code'=>'FEED_CONFLICT','last_source'=>'pull','version'=>$best['version']];kicomUpdateChannelStateWrite($now);
        kicomLivingEvent('update_feed_conflict','critical',['version'=>$best['version'],'hashes'=>$hashes]);
        return ['ok'=>false,'code'=>'FEED_CONFLICT','version'=>$best['version']];
    }
    $dl=kicomUpdateDownloadPackage((string)$best['url'],(string)$best['feed_url'],(string)$best['sha256']);
    if(!$dl['ok']){$now+=['last_code'=>$dl['code']??'DOWNLOAD_FAILED','last_source'=>'pull'];kicomUpdateChannelStateWrite($now);return $dl;}
    try{
        $r=kicomReceiveSelfUpdatePackage((string)$dl['path'],'KiCom-'.$best['version'].'.zip','pull:'.$best['feed_name'],$allowAuto);
    } finally {@unlink((string)($dl['path']??''));}
    $now+=['last_code'=>$r['code']??($r['ok']?'OK':'FAILED'),'last_source'=>'pull:'.$best['feed_name'],'version'=>$best['version'],'sha256'=>$best['sha256'],'risk_class'=>$r['risk_class']??($r['staged']['risk_class']??'')];kicomUpdateChannelStateWrite($now);
    return $r+['feed_errors'=>$errors,'feed_matches'=>count($same)];
}
function kicomSelfUpdatePending(): ?array {
    $f=kicomSelfUpdatePendingFile();if(!is_file($f))return null;$r=json_decode((string)@file_get_contents($f),true);return is_array($r)?$r:null;
}
function kicomSelfUpdatePackagePath(array $pending): ?string {
    $name=(string)($pending['package_file']??'');if(!preg_match('/^[a-f0-9]{64}\\.zip$/',$name))return null;$path=kicomSelfUpdatePackagesDir().'/'.$name;return is_file($path)?$path:null;
}
function kicomSelfUpdateAtomicWrite(string $relative,string $content,string $tx): array {
    $path=kicomSafeUpdatePath($relative);$stateSentinel=in_array((string)$path,['var/.htaccess','stage/.htaccess'],true);if($path===null||((str_starts_with($path,'var/')||str_starts_with($path,'stage/'))&&!$stateSentinel))return ['ok'=>false,'code'=>'UPDATE_PATH_FORBIDDEN'];
    $full=kicomBaseDir().'/'.$path;$parent=dirname($full);
    if(!is_dir($parent)&&!@mkdir($parent,0755,true)&&!is_dir($parent))return ['ok'=>false,'code'=>'UPDATE_DIR_CREATE_FAILED','path'=>$path];
    $tmp=$parent.'/.kicom-update-'.$tx.'-'.basename($path);
    if(@file_put_contents($tmp,$content,LOCK_EX)===false)return ['ok'=>false,'code'=>'UPDATE_TEMP_WRITE_FAILED','path'=>$path];
    @chmod($tmp,0644);
    if(!@rename($tmp,$full)){@unlink($tmp);return ['ok'=>false,'code'=>'UPDATE_RENAME_FAILED','path'=>$path];}
    clearstatcache(true,$full);if(function_exists('opcache_invalidate'))@opcache_invalidate($full,true);
    return ['ok'=>true,'sha256'=>hash('sha256',$content)];
}
function kicomSelfUpdateSnapshot(array $installFiles): array {
    $rows=[];$total=0;
    foreach($installFiles as $path=>$e){
        $full=kicomBaseDir().'/'.$path;$raw=is_file($full)?@file_get_contents($full):false;
        if($raw===false){$before='NEW';$b64='';$bytes=0;}else{$before=hash('sha256',(string)$raw);$b64=base64_encode((string)$raw);$bytes=strlen((string)$raw);}
        $total+=$bytes;if($total>KICOM_MAX_SELF_UPDATE_BACKUP_BYTES)return ['ok'=>false,'code'=>'UPDATE_BACKUP_TOO_LARGE'];
        $rows[]=['path'=>$path,'before_sha256'=>$before,'before_content_b64'=>$b64,'after_sha256'=>(string)$e['sha256']];
    }
    return ['ok'=>true,'files'=>$rows,'bytes'=>$total];
}
function kicomRestoreSelfUpdateSnapshot(array $rows,string $tx): array {
    foreach(array_reverse($rows) as $r){
        $path=kicomSafeUpdatePath((string)($r['path']??''));if($path===null)return ['ok'=>false,'code'=>'ROLLBACK_PATH_INVALID'];
        $full=kicomBaseDir().'/'.$path;$before=(string)($r['before_sha256']??'');
        if($before==='NEW'){if(is_file($full)&&!@unlink($full))return ['ok'=>false,'code'=>'ROLLBACK_DELETE_FAILED','path'=>$path];clearstatcache(true,$full);if(function_exists('opcache_invalidate'))@opcache_invalidate($full,true);continue;}
        $raw=base64_decode((string)($r['before_content_b64']??''),true);if($raw===false||!hash_equals($before,hash('sha256',$raw)))return ['ok'=>false,'code'=>'ROLLBACK_BACKUP_INVALID','path'=>$path];
        $w=kicomSelfUpdateAtomicWrite($path,$raw,'rb-'.$tx);if(!$w['ok'])return $w;
    }
    return ['ok'=>true];
}
function kicomSelfUpdateVerifyInstalled(array $installFiles): array {
    foreach($installFiles as $path=>$e){$full=kicomBaseDir().'/'.$path;if(!is_file($full))return ['ok'=>false,'code'=>'INSTALLED_FILE_MISSING','path'=>$path];$sha=hash_file('sha256',$full)?:'';if(!hash_equals((string)$e['sha256'],$sha))return ['ok'=>false,'code'=>'INSTALLED_HASH_MISMATCH','path'=>$path];}
    return ['ok'=>true];
}
function kicomSelfHealthUrl(): ?string {
    $host=(string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??'');
    if($host===''||!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/',$host))return null;
    $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
    $local=(bool)preg_match('/^(localhost|127\\.0\\.0\\.1)(?::[0-9]+)?$/i',$host);
    if(!$https&&!$local)return null;$scheme=$https?'https':'http';
    $script=(string)($_SERVER['SCRIPT_NAME']??'/admin.php');$dir=rtrim(str_replace('\\','/',dirname($script)),'/');if($dir==='.'||$dir==='/')$dir='';
    return $scheme.'://'.$host.$dir.'/?q=HELLO';
}
function kicomSelfHttpGet(string $url): array {
    if(function_exists('curl_init')){
        $ch=curl_init($url);if($ch!==false){
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>KICOM_HEALTH_TIMEOUT,CURLOPT_TIMEOUT=>KICOM_HEALTH_TIMEOUT,CURLOPT_USERAGENT=>'KiCom-SelfUpdate/'.KICOM_VERSION,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
            $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);
            return ['body'=>is_string($body)?substr($body,0,4096):false,'http_code'=>$code,'transport'=>'curl','transport_error'=>$errno?:null];
        }
    }
    $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>KICOM_HEALTH_TIMEOUT,'ignore_errors'=>true,'header'=>"User-Agent: KiCom-SelfUpdate/".KICOM_VERSION."\r\nConnection: close\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $body=@file_get_contents($url,false,$ctx,0,4096);$headers=$http_response_header??[];$code=0;foreach($headers as $h)if(preg_match('~^HTTP/\S+\s+(\d{3})~i',$h,$m)){$code=(int)$m[1];break;}
    return ['body'=>$body,'http_code'=>$code,'transport'=>'stream','transport_error'=>$body===false?'REQUEST_FAILED':null];
}
function kicomSelfHealthCheckVersion(string $version): array {
    $url=kicomSelfHealthUrl();if($url===null)return ['ok'=>false,'code'=>'SELF_HEALTH_URL_UNAVAILABLE'];
    $last=['ok'=>false,'http_code'=>0,'url_host'=>(string)parse_url($url,PHP_URL_HOST),'code'=>'SELF_HEALTHCHECK_FAILED','attempts'=>0,'actual_version'=>null];
    for($attempt=1;$attempt<=5;$attempt++){
        $resp=kicomSelfHttpGet($url);$body=$resp['body'];$code=(int)$resp['http_code'];
        $actual=null;if(is_string($body)&&preg_match('/FACT version="([^"]+)"/',$body,$vm))$actual=$vm[1];
        $ok=$code>=200&&$code<300&&is_string($body)&&str_contains($body,'OK hello')&&$actual===$version;
        $last=['ok'=>$ok,'http_code'=>$code,'url_host'=>(string)parse_url($url,PHP_URL_HOST),'code'=>$ok?null:'SELF_HEALTHCHECK_FAILED','attempts'=>$attempt,'actual_version'=>$actual,'transport'=>$resp['transport'],'transport_error'=>$resp['transport_error']];
        if($ok)return $last;if($attempt<5)usleep(750000);
    }
    return $last;
}
function kicomSelfUpdateHistoryRecord(array $row): ?string {
    try{$id=gmdate('YmdHis').'-'.bin2hex(random_bytes(4));}catch(Throwable $e){$id=gmdate('YmdHis').'-'.substr(hash('sha256',uniqid('',true)),0,8);}
    $row=['id'=>$id,'created_at'=>gmdate('c')]+$row;$json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($json===false||@file_put_contents(kicomSelfUpdateHistoryDir().'/'.$id.'.json',$json,LOCK_EX)===false)return null;@chmod(kicomSelfUpdateHistoryDir().'/'.$id.'.json',0600);
    $files=glob(kicomSelfUpdateHistoryDir().'/*.json')?:[];usort($files,fn($a,$b)=>(@filemtime($b)?:0)<=>(@filemtime($a)?:0));foreach(array_slice($files,KICOM_MAX_SELF_UPDATE_HISTORY) as $old)@unlink($old);
    return $id;
}
function kicomSelfUpdateHistory(): array {
    $rows=[];foreach(glob(kicomSelfUpdateHistoryDir().'/*.json')?:[] as $f){$r=json_decode((string)@file_get_contents($f),true);if(is_array($r))$rows[]=$r;}usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));return $rows;
}
function kicomApplySelfUpdateUnlocked(array $pending): array {
    $pkg=kicomSelfUpdatePackagePath($pending);if($pkg===null)return ['ok'=>false,'code'=>'PACKAGE_NOT_FOUND'];
    if(!hash_equals((string)($pending['from_version']??''),KICOM_VERSION))return ['ok'=>false,'code'=>'CURRENT_VERSION_CHANGED','current_version'=>KICOM_VERSION];
    $check=kicomSelfUpdateZipInspect($pkg);if(!$check['ok'])return $check;
    if(!hash_equals((string)($pending['zip_sha256']??''),(string)$check['zip_sha256'])||!hash_equals((string)($pending['to_version']??''),(string)$check['version']))return ['ok'=>false,'code'=>'PENDING_PACKAGE_MISMATCH'];
    $snapshot=kicomSelfUpdateSnapshot($check['install_files']);if(!$snapshot['ok'])return $snapshot;
    try{$tx=strtolower(bin2hex(random_bytes(4)));}catch(Throwable $e){$tx=strtolower(kicomRequestId());}
    $order=array_keys($check['install_files']);$critical=['genome/genome.json','living.php','lib.php','index.php','api.php','admin.php','guardian.php','recovery.php','.htaccess','MANIFEST.sha256'];usort($order,function($a,$b)use($critical){$ia=array_search($a,$critical,true);$ib=array_search($b,$critical,true);$wa=$ia===false?0:100+(int)$ia;$wb=$ib===false?0:100+(int)$ib;return $wa<=>$wb;});
    $failure=null;
    foreach($order as $path){$e=$check['install_files'][$path];$w=kicomSelfUpdateAtomicWrite($path,(string)$e['content'],$tx);if(!$w['ok']){$failure=$w;break;}}
    if($failure===null){$v=kicomSelfUpdateVerifyInstalled($check['install_files']);if(!$v['ok'])$failure=$v;}
    if($failure===null){$h=kicomSelfHealthCheckVersion((string)$check['version']);if(!$h['ok'])$failure=$h;}
    if($failure!==null){
        $rr=kicomRestoreSelfUpdateSnapshot($snapshot['files'],$tx);
        if($rr['ok'])kicomLivingCommitGenomeAfterInstall();
        $rh=$rr['ok']?kicomSelfHealthCheckVersion(KICOM_VERSION):['ok'=>false,'code'=>'ROLLBACK_NOT_APPLIED'];
        $restored=$rr['ok']&&($rh['ok']??false);
        $id=kicomSelfUpdateHistoryRecord(['action'=>'self_update','from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'package_sha256'=>$check['zip_sha256'],'status'=>$restored?'rolled_back':'rollback_failed','failure'=>$failure,'rollback_result'=>$rr,'rollback_health'=>$rh]);
        kicomLivingEvent('self_update_failed',$restored?'warn':'critical',['from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'failure_code'=>$failure['code']??'unknown','rollback_ok'=>$restored,'history_id'=>$id??'']);
        return ['ok'=>false,'code'=>$restored?'SELF_UPDATE_FAILED_ROLLED_BACK':'SELF_UPDATE_FAILED_ROLLBACK_FAILED','failure'=>$failure,'rollback_result'=>$rr,'rollback_health'=>$rh,'history_id'=>$id??''];
    }
    $genomeCommit=kicomLivingCommitGenomeAfterInstall();
    if(!$genomeCommit['ok']){
        $rr=kicomRestoreSelfUpdateSnapshot($snapshot['files'],$tx);if($rr['ok'])kicomLivingCommitGenomeAfterInstall();
        kicomLivingEvent('genome_promotion_failed','critical',['to_version'=>$check['version'],'rollback_ok'=>$rr['ok']??false]);
        return ['ok'=>false,'code'=>'GENOME_PROMOTION_FAILED','genome_result'=>$genomeCommit,'rollback_result'=>$rr];
    }
    $evoMeta=is_array($check['genome']['evolution']??null)?$check['genome']['evolution']:[];$evolutionary=!empty($evoMeta['counts_as_generation'])||str_starts_with((string)($pending['source']??''),'evolution:');
    $historyRow=['action'=>'self_update','from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'package_sha256'=>$check['zip_sha256'],'manifest_sha256'=>$check['manifest_sha256'],'genome_id'=>(string)($check['genome']['id']??''),'kernel_update'=>(bool)($check['kernel_update']??false),'source'=>(string)($pending['source']??'unknown'),'risk_class'=>(string)($pending['risk_class']??'red'),'risk_reasons'=>$pending['risk_reasons']??[],'package_file'=>basename($pkg),'status'=>'installed','files_count'=>count($check['install_files']),'snapshot'=>$snapshot['files'],'evolutionary'=>$evolutionary];
    $id=kicomSelfUpdateHistoryRecord($historyRow);$historyRow['id']=$id??'';$historyRow['created_at']=gmdate('c');
    @unlink(kicomSelfUpdatePendingFile());
    kicomLivingEvent('self_update_installed','info',['from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'genome_id'=>$check['genome']['id']??'','history_id'=>$id??'','source'=>(string)($pending['source']??'unknown'),'risk_class'=>(string)($pending['risk_class']??'red'),'evolutionary'=>$evolutionary]);
    if($evolutionary)kicomEvolutionObserveInstalledUpdate($historyRow,true);
    if(function_exists('kicomArchiveReleasePackage')){ $ar=kicomArchiveReleasePackage($pkg,$check,['source'=>(string)($pending['source']??'unknown'),'risk_class'=>(string)($pending['risk_class']??'')]); if(empty($ar['ok']))kicomLivingEvent('release_archive_failed','warn',['version'=>$check['version'],'code'=>$ar['code']??'UNKNOWN']); }
    if(function_exists('kicomPrimaryFeedPublishPackage')){ $pf=kicomPrimaryFeedPublishPackage($pkg,$check,['source'=>(string)($pending['source']??'unknown'),'risk_class'=>(string)($pending['risk_class']??'')]); if(empty($pf['ok']))kicomLivingEvent('primary_publish_failed','warn',['version'=>$check['version'],'code'=>$pf['code']??'UNKNOWN']); }
    return ['ok'=>true,'from_version'=>KICOM_VERSION,'to_version'=>$check['version'],'history_id'=>$id??'','files_count'=>count($check['install_files'])];
}
function kicomRollbackSelfUpdateUnlocked(string $historyId): array {
    $id=trim($historyId);if(!preg_match('/^[0-9]{14}-[a-f0-9]{8}$/',$id))return ['ok'=>false,'code'=>'HISTORY_ID_INVALID'];$file=kicomSelfUpdateHistoryDir().'/'.$id.'.json';if(!is_file($file))return ['ok'=>false,'code'=>'HISTORY_NOT_FOUND'];$h=json_decode((string)@file_get_contents($file),true);
    if(!is_array($h)||($h['action']??'')!=='self_update'||($h['status']??'')!=='installed'||!is_array($h['snapshot']??null))return ['ok'=>false,'code'=>'HISTORY_NOT_ROLLBACKABLE'];
    if(!hash_equals((string)($h['to_version']??''),KICOM_VERSION))return ['ok'=>false,'code'=>'CURRENT_VERSION_CHANGED','current_version'=>KICOM_VERSION];
    foreach($h['snapshot'] as $r){$path=(string)($r['path']??'');$full=kicomBaseDir().'/'.$path;$cur=is_file($full)?(hash_file('sha256',$full)?:''):'NEW';if(!hash_equals((string)($r['after_sha256']??''),$cur))return ['ok'=>false,'code'=>'ROLLBACK_CONFLICT','path'=>$path,'current_sha256'=>$cur];}
    try{$tx=strtolower(bin2hex(random_bytes(4)));}catch(Throwable $e){$tx=strtolower(kicomRequestId());}
    $rr=kicomRestoreSelfUpdateSnapshot($h['snapshot'],$tx);
    if(!$rr['ok']){
        $pkgName=(string)($h['package_file']??'');$pkgPath=preg_match('/^[a-f0-9]{64}\.zip$/',$pkgName)?kicomSelfUpdatePackagesDir().'/'.$pkgName:'';$reapply=['ok'=>false,'code'=>'REAPPLY_PACKAGE_UNAVAILABLE'];
        if($pkgPath!==''&&is_file($pkgPath)){$chk=kicomSelfUpdateZipInspect($pkgPath);if($chk['ok']&&(string)$chk['version']===(string)$h['to_version']){$reapply=['ok'=>true];foreach($chk['install_files'] as $path=>$e){$w=kicomSelfUpdateAtomicWrite($path,(string)$e['content'],'reapply-'.$tx);if(!$w['ok']){$reapply=$w;break;}}}}
        kicomLivingEvent('self_update_rollback_failed','critical',['source_update'=>$id,'reapplied'=>$reapply['ok']??false]);
        return ['ok'=>false,'code'=>$reapply['ok']?'ROLLBACK_FAILED_REAPPLIED':'ROLLBACK_FAILED_REAPPLY_FAILED','rollback_result'=>$rr,'reapply_result'=>$reapply];
    }
    $health=kicomSelfHealthCheckVersion((string)$h['from_version']);
    if(!$health['ok']){
        $pkgName=(string)($h['package_file']??'');$pkgPath=preg_match('/^[a-f0-9]{64}\.zip$/',$pkgName)?kicomSelfUpdatePackagesDir().'/'.$pkgName:'';$reapply=['ok'=>false,'code'=>'REAPPLY_PACKAGE_UNAVAILABLE'];
        if($pkgPath!==''&&is_file($pkgPath)){$chk=kicomSelfUpdateZipInspect($pkgPath);if($chk['ok']&&(string)$chk['version']===(string)$h['to_version']){$reapply=['ok'=>true];foreach($chk['install_files'] as $path=>$e){$w=kicomSelfUpdateAtomicWrite($path,(string)$e['content'],'reapply-'.$tx);if(!$w['ok']){$reapply=$w;break;}}if($reapply['ok'])$reapply['health']=kicomSelfHealthCheckVersion((string)$h['to_version']);}}
        $rid=kicomSelfUpdateHistoryRecord(['action'=>'self_update_rollback','source_update'=>$id,'from_version'=>$h['to_version'],'to_version'=>$h['from_version'],'status'=>$reapply['ok']&&($reapply['health']['ok']??false)?'rollback_health_failed_reapplied':'rollback_health_failed_reapply_failed','health'=>$health,'reapply_result'=>$reapply]);
        kicomLivingEvent('self_update_rollback_health_failed','critical',['source_update'=>$id,'reapplied'=>$reapply['ok']&&($reapply['health']['ok']??false),'history_id'=>$rid??'']);
        return ['ok'=>false,'code'=>$reapply['ok']&&($reapply['health']['ok']??false)?'ROLLBACK_HEALTHCHECK_FAILED_REAPPLIED':'ROLLBACK_HEALTHCHECK_FAILED_REAPPLY_FAILED','health'=>$health,'reapply_result'=>$reapply,'history_id'=>$rid??''];
    }
    $genomeRollback=kicomLivingCommitGenomeAfterInstall();
    if(!$genomeRollback['ok'])return ['ok'=>false,'code'=>'ROLLBACK_GENOME_REPIN_FAILED','genome_result'=>$genomeRollback];
    $rid=kicomSelfUpdateHistoryRecord(['action'=>'self_update_rollback','source_update'=>$id,'from_version'=>$h['to_version'],'to_version'=>$h['from_version'],'status'=>'rolled_back']);
    kicomLivingEvent('self_update_rolled_back','warn',['source_update'=>$id,'from_version'=>$h['to_version'],'to_version'=>$h['from_version'],'history_id'=>$rid??'']);
    return ['ok'=>true,'from_version'=>$h['to_version'],'to_version'=>$h['from_version'],'history_id'=>$rid??''];
}
function kicomApplySelfUpdate(array $pending): array {
    $target=(string)($pending['to_version']??'');
    if(!kicomImmuneMaintenanceBegin('self_update',$target)) return ['ok'=>false,'code'=>'MAINTENANCE_LOCK_FAILED'];
    try { return kicomApplySelfUpdateUnlocked($pending); }
    finally { kicomImmuneMaintenanceEnd(); }
}
function kicomRollbackSelfUpdate(string $historyId): array {
    if(!kicomImmuneMaintenanceBegin('self_update_rollback','')) return ['ok'=>false,'code'=>'MAINTENANCE_LOCK_FAILED'];
    try { return kicomRollbackSelfUpdateUnlocked($historyId); }
    finally { kicomImmuneMaintenanceEnd(); }
}
/* ---- end self-update controller ----------------------------------------- */

