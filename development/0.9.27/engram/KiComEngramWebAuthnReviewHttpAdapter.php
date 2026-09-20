<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramWebAuthnApprovalController.php';

/**
 * NOT REGISTERED. First-party JSON transport adapter for signed review.
 * The host must inject a genuine server-side first-party cookie/session lookup,
 * NOT accept request fields as identity. A DEV API bearer alone is insufficient.
 * Caller/host must independently enforce HTTPS, first-party TLS origin and
 * non-caching response; no public route is added by this source file.
 */
final class KiComEngramWebAuthnReviewHttpAdapter
{
    private KiComEngramWebAuthnApprovalController $approval;
    private $trustedBrowserSession;
    private string $expectedOrigin;
    public function __construct(
        KiComEngramWebAuthnApprovalController $approval,
        callable $trustedBrowserSession,
        string $expectedOrigin='https://kicom.rurtalbahn.info'
    ) {
        if (!preg_match('#\\Ahttps://[a-z0-9.-]+\\z#D',$expectedOrigin)) {
            throw new RuntimeException('ENGRAM_REVIEW_ORIGIN_INVALID');
        }
        $this->approval=$approval;
        $this->trustedBrowserSession=$trustedBrowserSession;
        $this->expectedOrigin=$expectedOrigin;
    }

    public function handle(array $server,string $rawBody): array
    {
        $headers=[
            'Content-Type'=>'application/json; charset=utf-8',
            'Cache-Control'=>'no-store, private',
            'Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer','X-Frame-Options'=>'DENY',
            'Access-Control-Allow-Origin'=>'',
        ];
        $reply=static fn(int $status,array $body):array=>[
            'http_status'=>$status,'headers'=>$headers,'body'=>$body
        ];
        if (($server['REQUEST_METHOD']??'')!=='POST') {
            return $reply(405,['ok'=>false,'code'=>'ENGRAM_REVIEW_POST_REQUIRED']);
        }
        if (($server['HTTPS']??'')!=='on') {
            return $reply(403,['ok'=>false,'code'=>'ENGRAM_REVIEW_HTTPS_REQUIRED']);
        }
        $origin=(string)($server['HTTP_ORIGIN']??'');
        if (!hash_equals($this->expectedOrigin,$origin)) {
            return $reply(403,['ok'=>false,'code'=>'ENGRAM_REVIEW_ORIGIN_DENIED']);
        }
        if (!preg_match('/\\Aapplication\\/json(?:;\\s*charset=utf-8)?\\z/i',
            trim((string)($server['CONTENT_TYPE']??'')))) {
            return $reply(415,['ok'=>false,'code'=>'ENGRAM_REVIEW_JSON_REQUIRED']);
        }
        if (strlen($rawBody)>16384) {
            return $reply(413,['ok'=>false,'code'=>'ENGRAM_REVIEW_BODY_TOO_LARGE']);
        }
        $data=json_decode($rawBody,true);
        if (!is_array($data) || array_is_list($data)
            || array_keys($data)!==['operation','payload']
            || !is_string($data['operation'])
            || !is_array($data['payload']) || array_is_list($data['payload'])) {
            return $reply(400,['ok'=>false,'code'=>'ENGRAM_REVIEW_REQUEST_INVALID']);
        }
        $operation=$data['operation'];$payload=$data['payload'];
        $keys=array_keys($payload);sort($keys);
        if ($operation==='ENGRAM_REVIEW_BEGIN') {
            if ($keys!==['csrf','review_id']
                || !is_string($payload['review_id'])
                || !is_string($payload['csrf'])) {
                return $reply(400,['ok'=>false,'code'=>'ENGRAM_REVIEW_REQUEST_INVALID']);
            }
        } elseif ($operation==='ENGRAM_REVIEW_CONFIRM') {
            if ($keys!==['assertion','challenge_id','csrf','review_id']
                || !is_string($payload['review_id'])
                || !is_string($payload['csrf'])
                || !is_string($payload['challenge_id'])
                || !is_array($payload['assertion'])) {
                return $reply(400,['ok'=>false,'code'=>'ENGRAM_REVIEW_REQUEST_INVALID']);
            }
        } else {
            return $reply(403,['ok'=>false,'code'=>'ENGRAM_REVIEW_OPERATION_FORBIDDEN']);
        }
        try {
            $browser=($this->trustedBrowserSession)($server);
            if (!is_array($browser)) {
                return $reply(401,['ok'=>false,'code'=>'ENGRAM_REVIEW_BROWSER_REQUIRED']);
            }
            if ($operation==='ENGRAM_REVIEW_BEGIN') {
                $options=$this->approval->begin(
                    $payload['review_id'],$payload['csrf'],$browser
                );
                return $reply(200,['ok'=>true,'code'=>'ENGRAM_REVIEW_BEGIN_OK']+$options);
            }
            $done=$this->approval->confirm(
                $payload['review_id'],$payload['csrf'],$payload['challenge_id'],
                $payload['assertion'],$browser
            );
            return $reply(200,[
                'ok'=>true,'code'=>'ENGRAM_REVIEW_APPROVED',
                'review_id'=>$done['review_id']
            ]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Do not echo private memory, server paths, session identifiers,
            // passkey material, reviewer metadata or raw failure diagnostics.
            return $reply(403,['ok'=>false,'code'=>'ENGRAM_REVIEW_DENIED']);
        } catch (Throwable $e) {
            return $reply(503,['ok'=>false,'code'=>'ENGRAM_REVIEW_UNAVAILABLE']);
        }
    }
}
