#!/usr/bin/env python3
"""Apply the first-party MCP route only to the exact original KiCom 0.9.30 api.php.

This produces source for a future full native release; it is NOT an update.
No live files, host config, credentials or database are modified.
"""
from __future__ import annotations
import hashlib, pathlib, sys

ORIGINAL_SHA256 = 'bcf9370548ec7b56f6afb90531dfb7ceb7a69fad2ffc024951e04d2c7066e428'
ANCHOR = '/* Engram native memory: no host-injected private trust => 404; no DEV bearer override. */'
ROUTE = """/* Mirage DEV-48: first-party MCP POST, disabled by default.
 * Trust comes ONLY from the private operator-owned host config, never HTTP. */
if (strtoupper((string)($_GET['q']??'')) === 'ENGRAM_MCP') {
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    try {
        if (!function_exists('kicomEngramServerRuntime'))
            throw new RuntimeException('MCP_UNAVAILABLE');
        $runtime = kicomEngramServerRuntime();
        if (!is_array($runtime) || ($runtime['enabled']??null)!==true
            || ($runtime['operator_approved']??null)!==true
            || ($runtime['host_isolation_verified']??null)!==true
            || ($runtime['mcp_connector_enabled']??null)!==true)
            throw new RuntimeException('MCP_UNAVAILABLE');
        require_once __DIR__.'/modules/engram/KiComEngramMcpFirstPartyBridge.php';
        $body=file_get_contents('php://input',false,null,0,8193);
        if (!is_string($body) || strlen($body)>8192)
            throw new RuntimeException('MCP_REQUEST_UNAVAILABLE');
        $result=KiComEngramMcpFirstPartyBridge::handle(
            $_SERVER,$body,$runtime,__DIR__,time()
        );
        foreach (($result['headers']??[]) as $key=>$value) {
            if (in_array($key,['Cache-Control','Content-Type','X-Content-Type-Options',
                    'X-Frame-Options','MCP-Protocol-Version','Vary'],true)
                && is_string($value) && !str_contains($value,"\\r")
                && !str_contains($value,"\\n")) header($key.': '.$value);
        }
        $status=$result['http_status']??404;
        http_response_code(is_int($status)&&in_array($status,[200,202,404],true)?$status:404);
        $output=$result['body']??'';
        if (!is_string($output) || strlen($output)>16384) $output='';
        echo $output;
    } catch (Throwable $error) {
        http_response_code(404);
    }
    exit;
}

"""

def prepare(source: bytes) -> bytes:
    if hashlib.sha256(source).hexdigest() != ORIGINAL_SHA256:
        raise ValueError('Base api.php differs from byte-verified 0.9.30')
    text=source.decode('utf-8')
    if text.count(ANCHOR)!=1 or "ENGRAM_MCP')" in text:
        raise ValueError('Ambiguous or previously patched api.php')
    return text.replace(ANCHOR,ROUTE+ANCHOR,1).encode('utf-8')

if __name__=='__main__':
    original=pathlib.Path(sys.argv[1])
    destination=pathlib.Path(sys.argv[2])
    code=prepare(original.read_bytes())
    destination.write_bytes(code)
    print('MIRAGE_FIRST_PARTY_MCP_ROUTER_PATCHED',hashlib.sha256(code).hexdigest())
