<?php
declare(strict_types=1);
require_once __DIR__.'/KiComEngramHostingPolicy.php';
require_once __DIR__.'/KiComEngramStore.php';

/**
 * Explicit original KiCom ADMIN action to create ONE known, artificial,
 * owner-scoped project entry for a real independent ChatGPT connector test.
 * The old diagnostic setup sample belongs to a different subject/namespace
 * and is deliberately NOT reachable from the owner-bound MCP reader.
 *
 * No user-supplied body, owner, namespace, file path, OAuth grant or memory
 * text is accepted. No private memory content is returned by this endpoint.
 * Never call from public api.php or a GET request.
 */
final class KiComEngramSyntheticMcpSampleHttp
{
    private const SAMPLE='MIRAGE ENGRAM CONNECTOR TEST GRUENE LOKOMOTIVE 2026';
    private const CONFIRM='KUENSTLICHEN ENGRAM TESTEINTRAG ANLEGEN';
    private const HEADERS=[
        'Cache-Control'=>'no-store, private',
        'Content-Type'=>'text/html; charset=utf-8',
        'X-Content-Type-Options'=>'nosniff',
        'X-Frame-Options'=>'DENY',
        'Referrer-Policy'=>'no-referrer',
    ];

    public static function handle(
        array $server,array $query,array $form,array $session,
        string $sid,string $csrf,string $trustedWebRoot,array $runtime
    ):array {
        if(($server['HTTPS']??null)!=='on'
            || ($server['HTTP_HOST']??null)!=='kicom.rurtalbahn.info'
            || ($session['admin']??null)!==true
            || !is_string($session['csrf']??null)
            || !hash_equals($session['csrf'],$csrf)
            || strlen($sid)<24 || strlen($csrf)<24
            || $query!==['engram_sample'=>'1'])
            return self::result(404,'');
        if(($server['REQUEST_METHOD']??null)==='GET')
            return self::result(200,self::page($csrf,null));
        if(($server['REQUEST_METHOD']??null)!=='POST'
            || !is_string($form['csrf']??null)
            || !hash_equals($csrf,$form['csrf'])
            || ($form['confirmation']??null)!==self::CONFIRM
            || array_keys($form)!==['csrf','confirmation']
            || (isset($server['HTTP_ORIGIN'])
                && $server['HTTP_ORIGIN']!=='https://kicom.rurtalbahn.info'))
            return self::result(403,'');
        try {
            if(!function_exists('kicomEngramServerRuntime'))return self::result(404,'');
            $live=kicomEngramServerRuntime();
            if(!is_array($live)||$runtime!==$live
                || ($live['enabled']??null)!==true
                || ($live['operator_approved']??null)!==true
                || ($live['review_enabled']??null)!==true
                || ($live['oauth_enabled']??null)!==true
                || ($live['mcp_connector_enabled']??null)!==true
                || !KiComEngramHostingPolicy::permits($live))
                return self::result(404,'');
            $web=realpath($trustedWebRoot);
            if(!is_string($web)||$web==='/'||is_link($trustedWebRoot)
                || ($live['web_root']??null)!==$web)
                return self::result(404,'');
            $data=dirname($web).'/engram-private/data';
            if(($live['data_dir']??null)!==$data
                || !self::privateDir($data)
                || !self::privateDb($data.'/mirage-activation.sqlite'))
                return self::result(404,'');
            $activation=new PDO('sqlite:'.$data.'/mirage-activation.sqlite',null,null,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
            ]);
            $q=$activation->query('SELECT state,owner_binding,host_evidence_id
                FROM activation_state WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
            if(!is_array($q)||($q['state']??null)!=='active'
                || !is_string($live['owner_binding']??null)
                || !hash_equals($live['owner_binding'],(string)($q['owner_binding']??''))
                || !is_string($live['host_evidence_id']??null)
                || !hash_equals($live['host_evidence_id'],(string)($q['host_evidence_id']??'')))
                return self::result(404,'');
            unset($activation);
            $store=new KiComEngramStore($data,$web);
            $existing=$store->search('mirage-owner','project',self::SAMPLE,2);
            if($existing===[]){
                $store->create('mirage-owner','project','technical',self::SAMPLE,
                    'synthetic_test','admin:mirage-mcp-smoke-v1');
                $status='Der künstliche Testeintrag wurde im privaten Engram-Speicher angelegt.';
            }else{
                if(count($existing)!==1||($existing[0]['body']??null)!==self::SAMPLE
                    || ($existing[0]['source_kind']??null)!=='synthetic_test')
                    return self::result(409,'');
                $status='Der künstliche Testeintrag ist bereits vorhanden.';
            }
            return self::result(200,self::page($csrf,$status));
        }catch(Throwable){return self::result(404,'');}
    }

    private static function page(string $csrf,?string $status):string
    {
        $safe=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $message=$status===null?'':'<p role="status">'.htmlspecialchars($status,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>';
        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Mirage – künstliche Test-Erinnerung</title></head><body><main>'
            .'<h1>Einmaligen künstlichen Engram-Testeintrag anlegen</h1>'
            .'<p>Der Eintrag gehört ausschließlich dem bereits freigegebenen Projekt-Benutzer.'
            .' Keine persönlichen Chat-Inhalte werden importiert.</p>'
            .'<p>Test-Suchbegriff: <strong>'.self::SAMPLE.'</strong></p>'.$message
            .'<form method="post" action="admin.php?engram_sample=1">'
            .'<input type="hidden" name="csrf" value="'.$safe.'">'
            .'<input type="hidden" name="confirmation" value="'.self::CONFIRM.'">'
            .'<button type="submit">Künstlichen Testeintrag jetzt anlegen</button></form>'
            .'<p><a href="admin.php">Zur KiCom-Administration</a></p></main></body></html>';
    }
    private static function result(int $status,string $body):array
    {
        return ['http_status'=>$status,'headers'=>self::HEADERS,'body'=>$body];
    }
    private static function privateDir(string $p):bool
    {
        clearstatcache(true,$p);$s=@lstat($p);
        return is_array($s)&&($s['mode']&0170000)===0040000
            &&($s['mode']&0077)===0&&!is_link($p)&&is_dir($p);
    }
    private static function privateDb(string $p):bool
    {
        clearstatcache(true,$p);$s=@lstat($p);
        return is_array($s)&&($s['mode']&0170000)===0100000
            &&($s['mode']&0077)===0&&($s['nlink']??0)===1
            &&($s['size']??0)>0&&!is_link($p)&&is_file($p);
    }
}
