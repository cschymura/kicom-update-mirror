<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramFirstPartyReview.php';
require_once __DIR__.'/KiComEngramPrivateOwnerRegistry.php';
if (!class_exists('KiComPasskeyBridge',false)) {
    require_once __DIR__.'/../../../source/0.9.26-r3/modules/dev/PasskeyBridge.php';
}

/**
 * ISOLATED DEV controller: actual KiCom WebAuthn signature verification, tied
 * to ONE displayed draft, ONE server-authenticated first-party browser session,
 * ONE fixed owner and ONE exact-content consent. No route is registered here.
 *
 * browserIdentity MUST be a KiCom server-side session lookup; it must NEVER
 * accept a user-supplied subject, browser_session_id or "verified" flag.
 * Only a trusted host may instantiate this controller and supply fixed private
 * paths and the existing first-party KiComPasskeyBridge / owner registry.
 */
final class KiComEngramWebAuthnApprovalController
{
    private KiComPasskeyBridge $passkeys;
    private KiComEngramPrivateOwnerRegistry $owners;
    private KiComEngramFirstPartyReview $review;
    private KiComEngramPrivateConsentLedger $consent;
    private PDO $challenges;
    private $browserIdentity;
    private ?array $attestation=null;

    public function __construct(
        string $challengeDirectory,string $draftDirectory,string $consentDirectory,
        string $publicRoot,KiComPasskeyBridge $passkeys,
        KiComEngramPrivateOwnerRegistry $owners,callable $serverBrowserIdentity
    ) {
        $this->passkeys=$passkeys;
        $this->owners=$owners;
        $this->browserIdentity=$serverBrowserIdentity;
        $web=realpath($publicRoot);$dir=realpath($challengeDirectory);
        if ($dir===false || $web===false || is_link($challengeDirectory)
            || (fileperms($dir)&0077)!==0
            || $dir===$web || str_starts_with($dir,$web.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('ENGRAM_STEPUP_STORAGE_INVALID');
        }
        $file=$dir.'/engram-stepup.sqlite';
        if (is_link($file)) throw new RuntimeException('ENGRAM_STEPUP_STORAGE_INVALID');
        $old=umask(0077);
        try {
            $this->challenges=new PDO('sqlite:'.$file,null,null,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
            ]);
            $this->challenges->exec('PRAGMA busy_timeout=5000');
            $this->challenges->exec('PRAGMA journal_mode=WAL');
            $this->challenges->exec('CREATE TABLE IF NOT EXISTS challenges (
                challenge_id TEXT PRIMARY KEY, review_id TEXT NOT NULL,
                csrf_sha256 TEXT NOT NULL, browser_sha256 TEXT NOT NULL,
                subject TEXT NOT NULL, binding_json TEXT NOT NULL,
                expires_at INTEGER NOT NULL, consumed INTEGER NOT NULL DEFAULT 0
            )');
            foreach ([$file,$file.'-wal',$file.'-shm'] as $p) {
                clearstatcache(true,$p);
                if (is_link($p) || (file_exists($p) && (!is_file($p)
                    || (fileperms($p)&0077)!==0 || (stat($p)['nlink']??0)!==1))) {
                    throw new RuntimeException('ENGRAM_STEPUP_STORAGE_INVALID');
                }
            }
        } finally {umask($old);}

        // There is no caller-controlled independentReview callback: the only
        // valid issuer receives this exact request-scoped cryptographic proof.
        $this->consent=new KiComEngramPrivateConsentLedger(
            $consentDirectory,$publicRoot,
            fn(array $binding):bool=>$this->attestation!==null
                && $this->attestation['binding']===$binding
        );
        $this->review=new KiComEngramFirstPartyReview(
            $draftDirectory,$publicRoot,$this->consent,
            function(array $session):array {
                $actor=$this->actor($session);
                $actor['fresh_passkey_assertion']=$this->attestation!==null
                    && $this->attestation['subject']===$actor['subject']
                    && hash_equals($this->attestation['browser_sha256'],
                        hash('sha256',$actor['browser_session_id']));
                return $actor;
            }
        );
    }

    private function actor(array $trustedBrowserSession): array
    {
        $actor=($this->browserIdentity)($trustedBrowserSession);
        if (!is_array($actor) || ($actor['verified']??null)!==true
            || ($actor['first_party_browser']??null)!==true
            || !is_string($actor['subject']??null)
            || preg_match('/\\A[a-z0-9][a-z0-9._:-]{0,63}\\z/D',$actor['subject'])!==1
            || !is_string($actor['browser_session_id']??null)
            || strlen($actor['browser_session_id'])<24
            || !is_array($actor['namespaces']??null)
            || !is_array($actor['engram_rights']??null)) {
            throw new RuntimeException('ENGRAM_BROWSER_IDENTITY_REQUIRED');
        }
        return $actor;
    }

    public function stage(array $trustedBrowserSession,array $entry): string
    {
        $actor=$this->actor($trustedBrowserSession);
        return $this->review->stage($actor,$entry);
    }

    public function render(string $reviewId,array $trustedBrowserSession): string
    {
        $this->actor($trustedBrowserSession);
        return $this->review->render($reviewId,$trustedBrowserSession);
    }

    /**
     * Begin only for a currently displayed draft whose CSRF is known by this
     * same first-party browser. The existing KiCom PasskeyBridge actually
     * generates the WebAuthn challenge and signed assertion options.
     */
    public function begin(
        string $reviewId,string $csrf,array $trustedBrowserSession
    ): array {
        $actor=$this->actor($trustedBrowserSession);
        $binding=$this->review->inspect($reviewId,$csrf,$trustedBrowserSession);
        if ($binding['subject']!==$actor['subject']
            || !in_array($binding['namespace'],$actor['namespaces'],true)
            || !in_array('engram.write',$actor['engram_rights'],true)) {
            throw new RuntimeException('ENGRAM_STEPUP_SCOPE_FORBIDDEN');
        }
        // Bridge's generic challenge format needs an ephemeral recipient key.
        // We do not export or use its private key; no encrypted handoff occurs.
        $keypair=sodium_crypto_box_keypair();
        try {
            $public=KiComPasskeyBridge::b64uEncode(
                sodium_crypto_box_publickey($keypair)
            );
        } finally {
            if (function_exists('sodium_memzero')) sodium_memzero($keypair);
        }
        $created=$this->passkeys->createAuthChallenge($public);
        if (empty($created['ok'])) throw new RuntimeException('ENGRAM_STEPUP_CHALLENGE_FAILED');
        $id=$created['challenge_id'];
        $opts=$this->passkeys->assertionOptions($id);
        if (empty($opts['ok'])) throw new RuntimeException('ENGRAM_STEPUP_OPTIONS_FAILED');
        $this->challenges->beginTransaction();
        try {
            $this->challenges->prepare('DELETE FROM challenges WHERE expires_at<=:now')
                ->execute([':now'=>time()]);
            $q=$this->challenges->prepare('INSERT INTO challenges
                (challenge_id,review_id,csrf_sha256,browser_sha256,subject,binding_json,expires_at)
                VALUES(:id,:review,:csrf,:browser,:subject,:binding,:expires)');
            $q->execute([
                ':id'=>$id,':review'=>$reviewId,':csrf'=>hash('sha256',$csrf),
                ':browser'=>hash('sha256',$actor['browser_session_id']),
                ':subject'=>$actor['subject'],
                ':binding'=>json_encode($binding,JSON_THROW_ON_ERROR),
                ':expires'=>time()+min(120,(int)$created['expires_in'])
            ]);
            $this->challenges->commit();
        } catch(Throwable $e){$this->challenges->rollBack();throw $e;}
        return ['challenge_id'=>$id,'publicKey'=>$opts['publicKey']];
    }

    /**
     * Only a valid, FRESH signed KiCom WebAuthn assertion for the mapped
     * passkey of the SAME first-party browser and SAME displayed record can
     * authorize the in-process exact-record consent and write receipt.
     * Invalid/mismatched attempts burn the challenge and require new begin().
     */
    public function confirm(
        string $reviewId,string $csrf,string $challengeId,array $assertion,
        array $trustedBrowserSession
    ): array {
        $actor=$this->actor($trustedBrowserSession);
        if (preg_match('/\\A[a-f0-9]{32}\\z/D',$challengeId)!==1) {
            throw new RuntimeException('ENGRAM_STEPUP_INVALID');
        }
        $binding=$this->review->inspect($reviewId,$csrf,$trustedBrowserSession);
        $this->challenges->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->challenges->prepare('SELECT * FROM challenges WHERE challenge_id=:id');
            $q->execute([':id'=>$challengeId]);$row=$q->fetch();
            if (!is_array($row) || (int)$row['consumed']!==0
                || (int)$row['expires_at']<=time()
                || $row['review_id']!==$reviewId
                || !hash_equals($row['csrf_sha256'],hash('sha256',$csrf))
                || !hash_equals($row['browser_sha256'],hash('sha256',$actor['browser_session_id']))
                || $row['subject']!==$actor['subject']
                || json_decode($row['binding_json'],true)!==$binding) {
                throw new RuntimeException('ENGRAM_STEPUP_CONTEXT_MISMATCH');
            }
            $used=$this->challenges->prepare('UPDATE challenges SET consumed=1
                WHERE challenge_id=:id AND consumed=0');
            $used->execute([':id'=>$challengeId]);
            if ($used->rowCount()!==1) throw new RuntimeException('ENGRAM_STEPUP_REPLAY');
            $this->challenges->exec('COMMIT');
        } catch(Throwable $e){$this->challenges->exec('ROLLBACK');throw $e;}

        $verified=$this->passkeys->verifyAssertion($challengeId,$assertion);
        if (empty($verified['ok'])
            || !is_string($verified['credential_id']??null)) {
            throw new RuntimeException('ENGRAM_STEPUP_SIGNATURE_DENIED');
        }
        $fingerprint=hash('sha256',$verified['credential_id']);
        $owner=($this->owners)($fingerprint);
        if (!is_array($owner) || ($owner['enabled']??null)!==true
            || !is_string($owner['credential_fingerprint']??null)
            || !hash_equals($fingerprint,$owner['credential_fingerprint'])
            || ($owner['subject']??null)!==$actor['subject']
            || !in_array($binding['namespace'],$owner['namespaces']??[],true)
            || !in_array('engram.write',$owner['engram_rights']??[],true)) {
            throw new RuntimeException('ENGRAM_STEPUP_OWNER_DENIED');
        }
        if ($this->attestation!==null) throw new RuntimeException('ENGRAM_STEPUP_REENTRANT');
        $this->attestation=[
            'subject'=>$actor['subject'],'binding'=>$binding,
            'browser_sha256'=>hash('sha256',$actor['browser_session_id'])
        ];
        try {
            // Review::confirm validates displayed draft and its actual CSRF;
            // ledger issuer then checks THIS SAME server-side binding.
            return $this->review->confirm(
                ['review_id'=>$reviewId,'csrf'=>$csrf,'decision'=>'approve_one'],
                $trustedBrowserSession
            );
        } finally {$this->attestation=null;}
    }

    public function consentLedgerForMemoryAdapter(): KiComEngramPrivateConsentLedger
    {
        // Host must keep this on its PRIVATE server side. Do not export raw
        // approval IDs, records or this object through the HTTP response.
        return $this->consent;
    }
}
