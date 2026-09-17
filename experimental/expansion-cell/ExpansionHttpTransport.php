<?php
declare(strict_types=1);

interface KiComExpansionTransport
{
    /** @return array<string,mixed> */
    public function getJson(string $url): array;
    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function postJson(string $url,array $body): array;
}

/** HTTPS-only, no-redirect transport pinned to one prepared child base URL. */
final class KiComExpansionHttpsTransport implements KiComExpansionTransport
{
    private string $baseUrl;
    private string $host;
    private string $pathPrefix;
    private int $timeout;
    private int $maxBytes;

    public function __construct(string $childBaseUrl,int $timeout=12,int $maxBytes=262144)
    {
        $this->baseUrl=rtrim(trim($childBaseUrl),'/');
        $p=parse_url($this->baseUrl);
        if (!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])) {
            throw new InvalidArgumentException('EXPANSION_HTTP_BASE_INVALID');
        }
        $this->host=strtolower((string)$p['host']);
        $this->pathPrefix=rtrim((string)($p['path']??''),'/').'/';
        $this->timeout=max(2,min(30,$timeout));
        $this->maxBytes=max(4096,min(1048576,$maxBytes));
    }

    public function getJson(string $url): array { return $this->request('GET',$url,null); }
    public function postJson(string $url,array $body): array { return $this->request('POST',$url,$body); }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function request(string $method,string $url,?array $body): array
    {
        $this->assertPinned($url);
        $headers=["Accept: application/json","User-Agent: KiCom-Expansion/1","Connection: close"];
        $content='';
        if ($body!==null) {
            $content=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if (!is_string($content)||strlen($content)>131072) return ['ok'=>false,'code'=>'EXPANSION_HTTP_BODY_TOO_LARGE'];
            $headers[]='Content-Type: application/json';
            $headers[]='Content-Length: '.strlen($content);
        }
        $ctx=stream_context_create([
            'http'=>[
                'method'=>$method,
                'header'=>implode("\r\n",$headers),
                'content'=>$content,
                'timeout'=>$this->timeout,
                'ignore_errors'=>true,
                'follow_location'=>0,
                'max_redirects'=>0,
                'protocol_version'=>1.1,
            ],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false],
        ]);
        $fh=@fopen($url,'rb',false,$ctx);
        if ($fh===false) return ['ok'=>false,'code'=>'EXPANSION_HTTP_CONNECT_FAILED'];
        $raw='';
        while(!feof($fh)&&strlen($raw)<=$this->maxBytes){$chunk=fread($fh,8192);if($chunk===false)break;$raw.=$chunk;}
        $meta=stream_get_meta_data($fh); fclose($fh);
        if (strlen($raw)>$this->maxBytes) return ['ok'=>false,'code'=>'EXPANSION_HTTP_RESPONSE_TOO_LARGE'];
        $status=0;
        foreach (($meta['wrapper_data']??[]) as $line) if (preg_match('#^HTTP/\S+\s+(\d{3})#',(string)$line,$m)) {$status=(int)$m[1];break;}
        if ($status<200||$status>=300) return ['ok'=>false,'code'=>'EXPANSION_HTTP_STATUS','http_status'=>$status];
        $decoded=json_decode($raw,true);
        if (!is_array($decoded)) return ['ok'=>false,'code'=>'EXPANSION_HTTP_JSON_INVALID','http_status'=>$status];

        // Success payloads are protocol data. In particular, federation replies
        // are signed over the exact JSON field set. Never inject transport
        // metadata such as HTTP status into a successful decoded payload before
        // signature verification; doing so invalidates every detached signature.
        return $decoded;
    }

    private function assertPinned(string $url): void
    {
        $p=parse_url($url);
        if (!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||strtolower((string)($p['host']??''))!==$this->host||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])) {
            throw new InvalidArgumentException('EXPANSION_HTTP_TARGET_MISMATCH');
        }
        $path=(string)($p['path']??'/');
        if (!str_starts_with($path,$this->pathPrefix)) throw new InvalidArgumentException('EXPANSION_HTTP_PATH_ESCAPE');
    }
}
