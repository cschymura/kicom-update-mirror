<?php
declare(strict_types=1);

/**
 * KiCom Development Zone session manager.
 *
 * Purpose: make development practical without weakening production gates.
 * DEV sessions are long-lived, non-rotating bearer sessions whose authority is
 * explicitly limited to isolated development capabilities. Tokens are shown
 * only at creation time and are stored server-side only as SHA-256 hashes.
 */
final class KiComDevSessionManager
{
    public const DEFAULT_TTL = 604800;      // 7 days
    public const DEFAULT_IDLE_TTL = 86400; // 24 hours

    /** @var list<string> */
    private const CAPABILITIES = [
        'source.snapshot.read',
        'workspace.read',
        'workspace.write',
        'workspace.delete',
        'workspace.history',
        'build.begin',
        'build.patch',
        'build.status',
        'build.test',
        'build.finalize_candidate',
        'candidate.read',
        'candidate.discard',
        'logs.read',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PREFIXES = [
        'production.',
        'deploy.production',
        'self_update.',
        'kernel.',
        'recovery.',
        'auth.',
        'secrets.',
    ];

    private string $storageDir;
    private int $ttl;
    private int $idleTtl;

    public function __construct(
        string $storageDir,
        int $ttl = self::DEFAULT_TTL,
        int $idleTtl = self::DEFAULT_IDLE_TTL
    ) {
        $this->storageDir = rtrim($storageDir, '/');
        $this->ttl = max(3600, min(1209600, $ttl));
        $this->idleTtl = max(1800, min(172800, $idleTtl));
        if ($this->idleTtl > $this->ttl) $this->idleTtl = $this->ttl;
    }

    public function ready(): array
    {
        if (!$this->ensureStorage()) return ['ok'=>false,'code'=>'DEV_SESSION_STORAGE_UNAVAILABLE'];
        return [
            'ok'=>true,
            'code'=>'READY',
            'ttl'=>$this->ttl,
            'idle_ttl'=>$this->idleTtl,
            'token_rotation'=>false,
            'scope'=>'dev',
            'capabilities'=>self::CAPABILITIES,
        ];
    }

    /** Plaintext token is returned once and never persisted. */
    public function issue(array $context = []): array
    {
        $ready=$this->ready();
        if (empty($ready['ok'])) return $ready;
        $this->cleanup();

        $id=bin2hex(random_bytes(12));
        $token=bin2hex(random_bytes(32));
        $now=time();
        $row=[
            'schema'=>1,
            'session_id'=>$id,
            'token_sha256'=>hash('sha256',$token),
            'scope'=>'dev',
            'state'=>'active',
            'created_at'=>gmdate('c',$now),
            'created_epoch'=>$now,
            'last_seen_at'=>gmdate('c',$now),
            'last_seen_epoch'=>$now,
            'expires_at'=>$now+$this->ttl,
            'idle_expires_at'=>$now+$this->idleTtl,
            'auth_method'=>substr((string)($context['auth_method']??'passkey'),0,32),
            'credential_id'=>substr((string)($context['credential_id']??''),0,256),
            'label'=>substr(trim((string)($context['label']??'KiCom DEV')),0,120),
            'capabilities'=>self::CAPABILITIES,
        ];
        if (!$this->writeJson($this->sessionFile($id),$row)) return ['ok'=>false,'code'=>'DEV_SESSION_WRITE_FAILED'];
        $this->appendAudit('issued',$id,['auth_method'=>$row['auth_method'],'label'=>$row['label']]);

        return [
            'ok'=>true,
            'code'=>'DEV_SESSION_ISSUED',
            'session_id'=>$id,
            'token'=>$token,
            'scope'=>'dev',
            'expires_in'=>$this->ttl,
            'idle_expires_in'=>$this->idleTtl,
            'token_rotates'=>false,
            'capabilities'=>self::CAPABILITIES,
        ];
    }

    /** A successful use extends idle expiry only; the bearer never rotates. */
    public function authenticate(string $sessionId,string $token,?string $requiredCapability=null): array
    {
        $sessionId=strtolower(trim($sessionId));
        $token=strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{24}$/',$sessionId)||!preg_match('/^[a-f0-9]{64}$/',$token)) {
            return ['ok'=>false,'code'=>'DEV_SESSION_CREDENTIAL_INVALID'];
        }
        if ($requiredCapability!==null&&!self::capabilityDefined($requiredCapability)) {
            return ['ok'=>false,'code'=>'DEV_CAPABILITY_FORBIDDEN'];
        }

        return $this->withSessionLock($sessionId,function(string $file) use ($sessionId,$token,$requiredCapability): array {
            $row=$this->readJson($file);
            if (!is_array($row)) return ['ok'=>false,'code'=>'DEV_SESSION_NOT_FOUND'];
            if (($row['state']??'')!=='active') return ['ok'=>false,'code'=>'DEV_SESSION_REVOKED'];

            $now=time();
            if ((int)($row['expires_at']??0)<$now) {
                $row['state']='expired';
                $row['expired_at']=gmdate('c',$now);
                $this->writeJson($file,$row);
                return ['ok'=>false,'code'=>'DEV_SESSION_EXPIRED'];
            }
            if ((int)($row['idle_expires_at']??0)<$now) {
                $row['state']='idle_expired';
                $row['idle_expired_at']=gmdate('c',$now);
                $this->writeJson($file,$row);
                return ['ok'=>false,'code'=>'DEV_SESSION_IDLE_EXPIRED'];
            }
            $expected=(string)($row['token_sha256']??'');
            if ($expected===''||!hash_equals($expected,hash('sha256',$token))) {
                return ['ok'=>false,'code'=>'DEV_SESSION_TOKEN_REJECTED'];
            }
            if ($requiredCapability!==null&&!in_array($requiredCapability,$row['capabilities']??[],true)) {
                return ['ok'=>false,'code'=>'DEV_CAPABILITY_FORBIDDEN'];
            }

            $row['last_seen_at']=gmdate('c',$now);
            $row['last_seen_epoch']=$now;
            $row['idle_expires_at']=min((int)$row['expires_at'],$now+$this->idleTtl);
            if (!$this->writeJson($file,$row)) return ['ok'=>false,'code'=>'DEV_SESSION_TOUCH_FAILED'];

            return [
                'ok'=>true,
                'code'=>'DEV_SESSION_OK',
                'session_id'=>$sessionId,
                'scope'=>'dev',
                'expires_in'=>max(0,(int)$row['expires_at']-$now),
                'idle_expires_in'=>max(0,(int)$row['idle_expires_at']-$now),
                'token_rotates'=>false,
                'capabilities'=>$row['capabilities'],
            ];
        });
    }

    /**
     * Revocation uses the same exclusive session lock as authenticate().
     * Therefore an in-flight authenticate cannot write an older active row over
     * a completed revoke and resurrect the credential.
     */
    public function revoke(string $sessionId,string $reason='manual'): array
    {
        $sessionId=strtolower(trim($sessionId));
        if (!preg_match('/^[a-f0-9]{24}$/',$sessionId)) return ['ok'=>false,'code'=>'DEV_SESSION_ID_INVALID'];
        $reason=substr(trim($reason),0,160);

        $result=$this->withSessionLock($sessionId,function(string $file) use ($reason): array {
            $row=$this->readJson($file);
            if (!is_array($row)) return ['ok'=>false,'code'=>'DEV_SESSION_NOT_FOUND'];
            if (($row['state']??'')==='revoked') return ['ok'=>true,'code'=>'DEV_SESSION_REVOKED','already_revoked'=>true];
            $row['state']='revoked';
            $row['revoked_at']=gmdate('c');
            $row['revoke_reason']=$reason;
            if (!$this->writeJson($file,$row)) return ['ok'=>false,'code'=>'DEV_SESSION_REVOKE_FAILED'];
            return ['ok'=>true,'code'=>'DEV_SESSION_REVOKED','already_revoked'=>false];
        });
        if (!empty($result['ok'])&&empty($result['already_revoked'])) {
            $this->appendAudit('revoked',$sessionId,['reason'=>$reason]);
        }
        return $result;
    }

    public function publicStatus(string $sessionId): array
    {
        $sessionId=strtolower(trim($sessionId));
        if (!preg_match('/^[a-f0-9]{24}$/',$sessionId)) return ['ok'=>false,'code'=>'DEV_SESSION_ID_INVALID'];
        $row=$this->readJson($this->sessionFile($sessionId));
        if (!is_array($row)) return ['ok'=>false,'code'=>'DEV_SESSION_NOT_FOUND'];
        return [
            'ok'=>true,
            'code'=>'DEV_SESSION_STATUS',
            'session_id'=>$sessionId,
            'scope'=>'dev',
            'state'=>(string)($row['state']??'unknown'),
            'created_at'=>(string)($row['created_at']??''),
            'last_seen_at'=>(string)($row['last_seen_at']??''),
            'expires_at'=>(int)($row['expires_at']??0),
            'idle_expires_at'=>(int)($row['idle_expires_at']??0),
            'label'=>(string)($row['label']??''),
            'capabilities'=>$row['capabilities']??[],
        ];
    }

    /** @return list<string> */
    public static function capabilities(): array { return self::CAPABILITIES; }

    public static function capabilityDefined(string $capability): bool
    {
        $capability=trim($capability);
        if ($capability==='') return false;
        foreach (self::FORBIDDEN_PREFIXES as $prefix) if (str_starts_with($capability,$prefix)) return false;
        return in_array($capability,self::CAPABILITIES,true);
    }

    /** @param Closure(string):array $fn */
    private function withSessionLock(string $id,Closure $fn): array
    {
        $lock=$this->lockFile($id);
        $fh=@fopen($lock,'c+');
        if ($fh===false) return ['ok'=>false,'code'=>'DEV_SESSION_LOCK_FAILED'];
        try {
            if (!flock($fh,LOCK_EX)) return ['ok'=>false,'code'=>'DEV_SESSION_LOCK_FAILED'];
            return $fn($this->sessionFile($id));
        } finally {
            @flock($fh,LOCK_UN);
            @fclose($fh);
        }
    }

    private function ensureStorage(): bool
    {
        foreach ([$this->storageDir,$this->storageDir.'/sessions'] as $dir) {
            if (!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)) return false;
            @chmod($dir,0700);
            $deny=$dir.'/.htaccess';
            if (!is_file($deny)) @file_put_contents($deny,"Options -Indexes\nRequire all denied\n",LOCK_EX);
        }
        return true;
    }

    private function cleanup(): void
    {
        $cutoff=time()-86400;
        foreach (glob($this->storageDir.'/sessions/*.json')?:[] as $file) {
            $row=$this->readJson($file);
            if (!is_array($row)) continue;
            $terminal=in_array((string)($row['state']??''),['expired','idle_expired','revoked'],true);
            $end=max((int)($row['expires_at']??0),(int)($row['idle_expires_at']??0));
            if ($terminal&&$end>0&&$end<$cutoff) {
                $id=basename($file,'.json');
                @unlink($file);
                @unlink($this->lockFile($id));
            }
        }
    }

    private function appendAudit(string $event,string $sessionId,array $extra=[]): void
    {
        $row=['at'=>gmdate('c'),'event'=>$event,'session_id'=>$sessionId]+$extra;
        $line=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (is_string($line)) @file_put_contents($this->storageDir.'/audit.jsonl',$line."\n",FILE_APPEND|LOCK_EX);
    }

    private function readJson(string $file): ?array
    {
        if (!is_file($file)) return null;
        $raw=@file_get_contents($file);
        if ($raw===false) return null;
        $row=json_decode($raw,true);
        return is_array($row)?$row:null;
    }

    private function writeJson(string $file,array $row): bool
    {
        $json=json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return false;
        $tmp=$file.'.tmp-'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp,$json."\n",LOCK_EX)===false) return false;
        @chmod($tmp,0600);
        if (!@rename($tmp,$file)) { @unlink($tmp); return false; }
        @chmod($file,0600);
        return true;
    }

    private function sessionFile(string $id): string { return $this->storageDir.'/sessions/'.$id.'.json'; }
    private function lockFile(string $id): string { return $this->storageDir.'/sessions/'.$id.'.lock'; }
}
