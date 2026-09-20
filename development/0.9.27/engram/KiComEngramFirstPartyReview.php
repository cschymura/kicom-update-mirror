<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramPrivateConsentLedger.php';

/**
 * First-party, SSR review form and bound one-use submission for ONE memory.
 * DEV ONLY, no route registration. Host MUST call stage() only after trusted
 * KiCom authentication, render/confirm only after independent server-side
 * first-party browser authentication and a fresh passkey step-up. A DEV bearer
 * token, JS-supplied "approved" flag, chat/Slack instruction or source text is
 * never a human-review credential. Host callbacks are not implemented here.
 *
 * Pending raw text resides temporarily in a private 0700 SQLite directory;
 * production data-at-rest protection, host isolation and deletion/retention
 * still require separate verification before enabling for real memories.
 */
final class KiComEngramFirstPartyReview
{
    private PDO $db;
    private KiComEngramPrivateConsentLedger $ledger;
    private $reviewer;
    private const ENTRY_FIELDS=['namespace','kind','body','source_kind','source_ref','sensitivity'];
    public function __construct(
        string $privateDirectory, string $publicRoot,
        KiComEngramPrivateConsentLedger $ledger, callable $verifiedBrowserReviewer
    ) {
        $real=realpath($privateDirectory);
        $web=realpath($publicRoot);
        if ($real===false || $web===false || is_link($privateDirectory)
            || !is_dir($real) || (fileperms($real)&0077)!==0
            || $real===$web || str_starts_with($real,$web.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('ENGRAM_REVIEW_STORAGE_INVALID');
        }
        $file=$real.'/engram-pending-review.sqlite';
        if (is_link($file)) throw new RuntimeException('ENGRAM_REVIEW_STORAGE_INVALID');
        $old=umask(0077);
        try {
            $this->db=new PDO('sqlite:'.$file,null,null,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
            ]);
            $this->db->exec('PRAGMA busy_timeout=5000');
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA synchronous=FULL');
            $this->db->exec('CREATE TABLE IF NOT EXISTS drafts(
                id TEXT PRIMARY KEY, subject TEXT NOT NULL, entry_json TEXT NOT NULL,
                csrf_hash TEXT, displayed_at INTEGER, expires_at INTEGER NOT NULL,
                status TEXT NOT NULL CHECK(status IN ("pending","approved"))
            )');
            foreach ([$file,$file.'-wal',$file.'-shm'] as $p) {
                if (is_link($p)) throw new RuntimeException('ENGRAM_REVIEW_STORAGE_INVALID');
                if (file_exists($p) && (!is_file($p)
                    || (fileperms($p)&0077)!==0 || (stat($p)['nlink']??0)!==1)) {
                    throw new RuntimeException('ENGRAM_REVIEW_STORAGE_INVALID');
                }
            }
        } finally {umask($old);}
        $this->ledger=$ledger;
        $this->reviewer=$verifiedBrowserReviewer;
    }

    private static function subject(array $auth,bool $fresh): string
    {
        if (($auth['verified']??null)!==true
            || ($auth['first_party_browser']??null)!==true
            || ($fresh && ($auth['fresh_passkey_assertion']??null)!==true)
            || !is_string($auth['subject']??null)
            || preg_match('/\\A[a-z0-9][a-z0-9._:-]{0,63}\\z/D',$auth['subject'])!==1) {
            throw new RuntimeException('ENGRAM_REVIEW_AUTH_REQUIRED');
        }
        return $auth['subject'];
    }

    private static function binding(string $subject,array $entry): array
    {
        $keys=array_keys($entry);sort($keys);$expected=self::ENTRY_FIELDS;sort($expected);
        if ($keys!==$expected || !is_string($entry['body'])
            || strlen($entry['body'])===0 || strlen($entry['body'])>4096
            || !preg_match('//u',$entry['body'])) {
            throw new RuntimeException('ENGRAM_REVIEW_ENTRY_INVALID');
        }
        foreach (self::ENTRY_FIELDS as $field) {
            if (!is_string($entry[$field])) throw new RuntimeException('ENGRAM_REVIEW_ENTRY_INVALID');
        }
        foreach (['namespace','kind','source_kind','sensitivity'] as $field) {
            if (preg_match('/\\A[a-z0-9][a-z0-9._:-]{0,63}\\z/D',$entry[$field])!==1) {
                throw new RuntimeException('ENGRAM_REVIEW_ENTRY_INVALID');
            }
        }
        if (!preg_match('/\\A(?:note|summary):[a-z0-9._:-]{1,80}\\z/D',$entry['source_ref'])
            || $entry['sensitivity']!=='ordinary') {
            throw new RuntimeException('ENGRAM_REVIEW_ENTRY_INVALID');
        }
        return [
            'subject'=>$subject,'namespace'=>$entry['namespace'],'kind'=>$entry['kind'],
            'body_sha256'=>hash('sha256',$entry['body']),
            'source_kind'=>$entry['source_kind'],
            'source_ref_sha256'=>hash('sha256',$entry['source_ref']),
            'sensitivity'=>$entry['sensitivity']
        ];
    }

    /**
     * Only invoke from an independently authorized KiCom staging action.
     * Never expose stage() as a public or agent-writable consent endpoint.
     */
    public function stage(array $trustedActor,array $entry): string
    {
        $subject=self::subject($trustedActor,false);
        $binding=self::binding($subject,$entry);
        if (!in_array($entry['namespace'],$trustedActor['namespaces']??[],true)
            || !in_array('engram.write',$trustedActor['engram_rights']??[],true)) {
            throw new RuntimeException('ENGRAM_REVIEW_SCOPE_FORBIDDEN');
        }
        $id=bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('INSERT INTO drafts
                (id,subject,entry_json,expires_at,status)
                VALUES(:id,:subject,:entry,:expiry,"pending")');
            $q->execute([
                ':id'=>$id,':subject'=>$subject,
                ':entry'=>json_encode($entry,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                ':expiry'=>time()+300,
            ]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack();throw $e; }
        return $id;
    }

    /** Returns HTML to an already authenticated first-party browser, NOT chat. */
    public function render(string $id,array $serverBrowserAuth): string
    {
        $subject=self::subject(($this->reviewer)($serverBrowserAuth),false);
        if (!preg_match('/\\A[a-f0-9]{32}\\z/D',$id)) {
            throw new RuntimeException('ENGRAM_REVIEW_NOT_FOUND');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT * FROM drafts WHERE id=:id');
            $q->execute([':id'=>$id]);$row=$q->fetch();
            if (!is_array($row) || !hash_equals($row['subject'],$subject)
                || $row['status']!=='pending' || (int)$row['expires_at']<=time()) {
                throw new RuntimeException('ENGRAM_REVIEW_NOT_FOUND');
            }
            $entry=json_decode($row['entry_json'],true,512,JSON_THROW_ON_ERROR);
            $binding=self::binding($subject,$entry);
            $csrf=bin2hex(random_bytes(32));
            $u=$this->db->prepare('UPDATE drafts SET csrf_hash=:hash,displayed_at=:now
                WHERE id=:id AND status="pending"');
            $u->execute([':hash'=>hash('sha256',$csrf),':now'=>time(),':id'=>$id]);
            $this->db->exec('COMMIT');
        } catch(Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
        $esc=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        // Only fixed HTML; never use client-submitted body on confirmation.
        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta http-equiv="Cache-Control" content="no-store">'
            .'<title>Engram – einzelne Erinnerung prüfen</title></head><body>'
            .'<main><h1>Genau eine Erinnerung freigeben</h1>'
            .'<p>Nur dieser angezeigte Inhalt wird für diese Freigabe verwendet.</p>'
            .'<dl><dt>Bereich</dt><dd>'.$esc($entry['namespace']).'</dd>'
            .'<dt>Art</dt><dd>'.$esc($entry['kind']).'</dd>'
            .'<dt>Quelle</dt><dd>'.$esc($entry['source_ref']).'</dd></dl>'
            .'<pre style="white-space:pre-wrap;overflow-wrap:anywhere">'
            .$esc($entry['body']).'</pre>'
            .'<form method="post" autocomplete="off">'
            .'<input type="hidden" name="review_id" value="'.$esc($id).'">'
            .'<input type="hidden" name="csrf" value="'.$esc($csrf).'">'
            .'<button type="submit" name="decision" value="approve_one">'
            .'Diese eine Erinnerung freigeben</button></form></main></body></html>';
    }

    /**
     * Called by a FIRST-PARTY verified browser POST after displaying the text.
     * Host MUST provide fresh independently verified human WebAuthn assertion
     * for the same mapped subject and enforce no-store, HTTPS and CSRF origin.
     * Never treat the form value alone as human authentication.
     */
    public function confirm(array $post,array $serverBrowserAuth): array
    {
        $auth=($this->reviewer)($serverBrowserAuth);
        $subject=self::subject($auth,true);
        $keys=array_keys($post);sort($keys);
        if ($keys!==['csrf','decision','review_id']
            || $post['decision']!=='approve_one'
            || !is_string($post['csrf'])
            || !preg_match('/\\A[a-f0-9]{64}\\z/D',$post['csrf'])
            || !is_string($post['review_id'])
            || !preg_match('/\\A[a-f0-9]{32}\\z/D',$post['review_id'])) {
            throw new RuntimeException('ENGRAM_REVIEW_POST_INVALID');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT * FROM drafts WHERE id=:id');
            $q->execute([':id'=>$post['review_id']]);$row=$q->fetch();
            if (!is_array($row) || !hash_equals($row['subject'],$subject)
                || $row['status']!=='pending' || (int)$row['expires_at']<=time()
                || !is_string($row['csrf_hash'])
                || (int)$row['displayed_at']<=0
                || !hash_equals($row['csrf_hash'],hash('sha256',$post['csrf']))) {
                throw new RuntimeException('ENGRAM_REVIEW_CONFIRM_DENIED');
            }
            $entry=json_decode($row['entry_json'],true,512,JSON_THROW_ON_ERROR);
            $binding=self::binding($subject,$entry);
            // The consent ledger must use a trusted callback bound to this
            // independently verified same-subject review of this exact draft.
            $receipt=$this->ledger->issue($binding,180);
            $q=$this->db->prepare('UPDATE drafts SET status="approved",
                csrf_hash=NULL,entry_json="{}" WHERE id=:id AND status="pending"');
            $q->execute([':id'=>$post['review_id']]);
            if ($q->rowCount()!==1) throw new RuntimeException('ENGRAM_REVIEW_CONFLICT');
            $this->db->exec('COMMIT');
            return ['ok'=>true,'code'=>'ENGRAM_REVIEW_APPROVED',
                'binding'=>$binding,'receipt_id'=>$receipt['receipt_id']];
        } catch(Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
}
