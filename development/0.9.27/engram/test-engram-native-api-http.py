#!/usr/bin/env python3
"""Synthetic localhost HTTP acceptance against the ACTUAL unpacked KiCom ZIP.

No production connection, real passkeys, personal memory, secrets or installer.
Host config below is injected ONLY by this disposable CI local server shim.
"""
from __future__ import annotations
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

if len(sys.argv) != 2:
    raise SystemExit("usage: test-engram-native-api-http.py /path/to/unpacked/release")
web = Path(sys.argv[1]).resolve()
assert (web / "api.php").is_file()
BODY = "Synthetic mint locomotive persisted through the actual native KiCom HTTP endpoint."
ENTRY = {
    "namespace": "project", "kind": "technical", "body": BODY,
    "source_kind": "approved_summary", "source_ref": "summary:synthetic-native-http",
    "sensitivity": "ordinary",
}
WRITE = json.dumps({"operation": "ENGRAM_REMEMBER", "payload": ENTRY}).encode()
READ = json.dumps({"operation": "ENGRAM_RECALL", "payload": {
    "namespace": "project", "query": "mint locomotive", "limit": 3,
}}).encode()

def request(url: str, body: bytes, session: dict | None = None) -> tuple[int, dict]:
    headers = {"Content-Type": "application/json"}
    if session:
        headers["X-Kicom-Dev-Session"] = session["session_id"]
        headers["X-Kicom-Dev-Token"] = session["token"]
    try:
        response = urllib.request.urlopen(urllib.request.Request(
            url, data=body, headers=headers, method="POST"), timeout=4)
    except urllib.error.HTTPError as err:
        response = err
    with response:
        return response.status, json.load(response)

def start_server(port: int, bootstrap: Path | None = None, env: dict | None = None):
    command = ["php"]
    if bootstrap is not None:
        command.extend(["-d", "auto_prepend_file=" + str(bootstrap)])
    command.extend(["-S", "127.0.0.1:" + str(port), "-t", str(web)])
    server = subprocess.Popen(
        command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL, env=env)
    for _ in range(70):
        if server.poll() is not None:
            raise RuntimeError("Synthetic KiCom HTTP server terminated unexpectedly")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                return server
        except OSError:
            time.sleep(0.06)
    server.terminate()
    raise RuntimeError("Synthetic KiCom HTTP server could not start")

def stop_server(server):
    server.terminate()
    try:
        server.wait(timeout=4)
    except subprocess.TimeoutExpired:
        server.kill()
        server.wait(timeout=4)

with tempfile.TemporaryDirectory(prefix="kicom-native-http-") as temp:
    private = Path(temp)
    private.chmod(0o700)
    for folder in ("data", "backups", "owners", "consent", "dev-sessions"):
        (private / folder).mkdir(mode=0o700)
    bootstrap = private / "synthetic-bootstrap.php"
    bootstrap.write_text("""<?php
declare(strict_types=1);
// STRICTLY CI-ONLY SAPI shim: real hosting MUST verify HTTPS itself.
$_SERVER['HTTPS']='on';
function kicomEngramServerRuntime(): array {
    return json_decode(file_get_contents(getenv('ENGRAM_TEST_RUNTIME_JSON')),true,
        512,JSON_THROW_ON_ERROR);
}
""")
    bootstrap.chmod(0o600)
    config = private / "trusted-runtime.json"
    config.write_text(json.dumps({
        "enabled": True,
        "operator_approved": True,
        "host_isolation_verified": True,
        "runtime_source": "server-only-reviewed",
        "private_memory_scope": "dev-verified-owner",
        "web_root": str(web),
        "reviewed_web_roots": [str(web)],
        "data_dir": str(private / "data"),
        "backups_dir": str(private / "backups"),
        "dev_session_root": str(private / "dev-sessions"),
        "owner_registry": str(private / "owners" / "engram-owners.json"),
        "consent_dir": str(private / "consent"),
    }))
    config.chmod(0o600)
    fixture = r"""
$web=$argv[1];$private=$argv[2];
require $web.'/modules/dev/DevSession.php';
require $web.'/modules/engram/KiComEngramPrivateConsentLedger.php';
$keyA='synthetic_http_key_A_0011223344556677';
$keyB='synthetic_http_key_B_0011223344556677';
$manager=new KiComDevSessionManager($private.'/dev-sessions');
$a=$manager->issue(['auth_method'=>'passkey','credential_id'=>$keyA]);
$a2=$manager->issue(['auth_method'=>'passkey','credential_id'=>$keyA]);
$b=$manager->issue(['auth_method'=>'passkey','credential_id'=>$keyB]);
$fpa=hash('sha256',$keyA);$fpb=hash('sha256',$keyB);
$owner=static function(string $fp,string $subject,array $rights):array {
    return ['enabled'=>true,'credential_fingerprint'=>$fp,'subject'=>$subject,
        'namespaces'=>['project'],'engram_rights'=>$rights];
};
file_put_contents($private.'/owners/engram-owners.json',json_encode([
    'schema'=>1,'owners'=>[
        $fpa=>$owner($fpa,'synthetic-http-a',['engram.read','engram.write']),
        $fpb=>$owner($fpb,'synthetic-http-b',['engram.read'])
    ]],JSON_THROW_ON_ERROR));
chmod($private.'/owners/engram-owners.json',0600);
$body='Synthetic mint locomotive persisted through the actual native KiCom HTTP endpoint.';
$binding=['subject'=>'synthetic-http-a','namespace'=>'project','kind'=>'technical',
    'body_sha256'=>hash('sha256',$body),
    'source_kind'=>'approved_summary',
    'source_ref_sha256'=>hash('sha256','summary:synthetic-native-http'),
    'sensitivity'=>'ordinary'];
// ONLY synthetic fixture approval, NOT automatically issued in live API.
$ledger=new KiComEngramPrivateConsentLedger(
    $private.'/consent',$web,
    static fn(array $candidate):bool=>$candidate===$binding);
$ledger->issue($binding);
echo json_encode(['a'=>$a,'a2'=>$a2,'b'=>$b],JSON_THROW_ON_ERROR);
"""
    fixtures = subprocess.run(
        ["php", "-r", fixture, str(web), str(private)],
        check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    identities = json.loads(fixtures.stdout)
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    url = f"http://127.0.0.1:{port}/api.php?q=ENGRAM_MEMORY"
    disabled = start_server(port)
    try:
        status, payload = request(url, WRITE, identities["a"])
        assert status == 404 and payload["code"] == "ENGRAM_MEMORY_UNAVAILABLE"
        assert not (private / "data" / "engrams.sqlite").exists()
        print("ENGRAM_NATIVE_HTTP_DEFAULT_404_WITHOUT_TRUSTED_BOOTSTRAP=1")
    finally:
        stop_server(disabled)
    env = dict(os.environ)
    env["ENGRAM_TEST_RUNTIME_JSON"] = str(config)
    active = start_server(port, bootstrap, env)
    try:
        status, payload = request(url, WRITE, identities["b"])
        assert status == 403, (status, payload)
        print("ENGRAM_NATIVE_HTTP_OTHER_OWNER_WRITE_DENIED=1")
        status, payload = request(url, WRITE, identities["a"])
        assert status == 200 and payload["code"] == "ENGRAM_REMEMBER_OK", (status, payload)
        print("ENGRAM_NATIVE_HTTP_EXACT_CONSENT_WRITE_OK=1")
        status, payload = request(url, WRITE, identities["a"])
        assert status == 403, (status, payload)
        print("ENGRAM_NATIVE_HTTP_ONE_TIME_CONSENT_REPLAY_DENIED=1")
        status, payload = request(url, READ, identities["a2"])
        assert status == 200 and payload["records"][0]["body"] == BODY, (status, payload)
        print("ENGRAM_NATIVE_HTTP_INDEPENDENT_SESSION_RECALL_OK=1")
        status, payload = request(url, READ, identities["b"])
        assert status == 200 and payload["count"] == 0, (status, payload)
        print("ENGRAM_NATIVE_HTTP_CROSS_OWNER_READ_DENIED=1")
        status, payload = request(url, READ)
        assert status == 401, (status, payload)
        print("ENGRAM_NATIVE_HTTP_ANONYMOUS_READ_DENIED=1")
    finally:
        stop_server(active)
print("ENGRAM_NATIVE_HTTP_SYNTHETIC_ACCEPTANCE_PASSED=7")
