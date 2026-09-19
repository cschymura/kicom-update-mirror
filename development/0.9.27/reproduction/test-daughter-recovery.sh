#!/usr/bin/env bash
set -euo pipefail
# Disposable GitHub CI only; after daughter runtime and host lineage tests.
# Separate daughter genesis DB; do not modify native R3 DB or mother memory.
root="/tmp/kicom-membrane-os-$GITHUB_RUN_ID"
daughter="$root/daughter"
database="$daughter/genesis.sqlite"
backup="$root/recovery/daughter-genesis-snapshot.sqlite"
restored="$daughter/genesis-restored.sqlite"
damaged="$daughter/genesis-damaged-fixture.sqlite"
sudo test -f "$root/daughter-candidate.json"
sudo test ! -e "$database"
sudo test ! -e "$backup"
sudo -u kicom_daughter_ci php -r '
  $root=$argv[1];$db=$argv[2];
  $c=json_decode(file_get_contents($root."/daughter-candidate.json"),true,16,JSON_THROW_ON_ERROR);
  if(!is_array($c)||!$c["ok"]||$c["native_genome_bound"]
    ||$c["deployment_authorized"])exit(2);
  $p=new PDO("sqlite:".$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $p->exec("PRAGMA journal_mode=WAL");
  $p->exec("PRAGMA synchronous=FULL");
  $p->exec("CREATE TABLE genesis(
     singleton INTEGER PRIMARY KEY CHECK(singleton=1),
     public_fingerprint TEXT NOT NULL, parent_fingerprint TEXT NOT NULL,
     source_sha256 TEXT NOT NULL, candidate_sha256 TEXT NOT NULL)");
  $s=$p->prepare("INSERT INTO genesis VALUES(1,?,?,?,?)");
  $s->execute([$c["child_public_fingerprint"],$c["parent_public_fingerprint"],
    $c["source_release_sha256"],hash("sha256",file_get_contents($root."/daughter-candidate.json"))]);
  $p=null;
  chmod($db,0600);
' "$root" "$database"
sudo test "$(sudo stat -c '%U:%a' "$database")" = 'kicom_daughter_ci:600'
echo 'PASS independent daughter genesis SQLite belongs solely to daughter identity'

sudo -u kicom_daughter_ci php -r '
  $p=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  if($p->query("PRAGMA quick_check")->fetchColumn()!=="ok")exit(2);
  if($p->query("SELECT count(*) FROM genesis")->fetchColumn()!=1)exit(3);
  $j=$p->query("PRAGMA journal_mode")->fetchColumn();
  if(strtolower((string)$j)!=="wal")exit(4);
' "$database"
echo 'PASS standalone daughter genesis SQLite WAL and quick_check clean'

sudo -u kicom_daughter_ci php -r '
  $root=$argv[1];$p=new PDO("sqlite:".$argv[2]);
  $row=$p->query("SELECT * FROM genesis WHERE singleton=1")->fetch(PDO::FETCH_ASSOC);
  $a=json_decode(file_get_contents($root."/daughter-candidate.json"),true,16,JSON_THROW_ON_ERROR);
  if($row["public_fingerprint"]!==$a["child_public_fingerprint"]
     ||$row["parent_fingerprint"]!==$a["parent_public_fingerprint"]
     ||$row["source_sha256"]!==$a["source_release_sha256"]
     ||$row["candidate_sha256"]!==hash("sha256",file_get_contents($root."/daughter-candidate.json")))exit(2);
' "$root" "$database"
echo 'PASS new SQLite state binds daughter public fingerprint and exact host candidate digest'

if sudo -u kicom_inner_ci cat "$database" >/dev/null 2>&1; then
  echo 'FAIL mother read daughter genesis SQLite' >&2; exit 1
fi
echo 'PASS mother cannot read daughter genesis database'

# VACUUM INTO captures a consistent SQLite snapshot even in WAL mode.
# The host creates it inside its independent 0700 recovery root.
sudo php -r '
  $db=$argv[1];$out=$argv[2];
  if(file_exists($out)||is_link($out))exit(2);
  $p=new PDO("sqlite:".$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $p->exec("VACUUM INTO ".$p->quote($out));
' "$database" "$backup"
sudo chmod 0400 "$backup"
sudo test "$(sudo stat -c '%U:%a' "$backup")" = 'root:400'
sudo php -r '
  $p=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  if($p->query("PRAGMA quick_check")->fetchColumn()!=="ok")exit(2);
  if($p->query("SELECT count(*) FROM genesis")->fetchColumn()!=1)exit(3);
' "$backup"
echo 'PASS root-only independent recovery root holds verified WAL-consistent SQLite snapshot'

if sudo -u kicom_daughter_ci cat "$backup" >/dev/null 2>&1; then
  echo 'FAIL daughter could read protected recovery backup' >&2; exit 1
fi
echo 'PASS daughter cannot access independent recovery snapshot'
if sudo -u kicom_inner_ci cat "$backup" >/dev/null 2>&1; then
  echo 'FAIL mother could read protected recovery backup' >&2; exit 1
fi
echo 'PASS mother cannot access independent recovery snapshot'

# Test damage only on a separate disposable copy; original DB remains intact.
sudo cp "$backup" "$damaged"
sudo chown kicom_daughter_ci:kicom_daughter_ci "$damaged"
sudo chmod 0600 "$damaged"
sudo -u kicom_daughter_ci sh -c 'printf corrupt > "$1"' sh "$damaged"
if sudo -u kicom_daughter_ci php -r '
  try {
    $p=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    exit($p->query("PRAGMA quick_check")->fetchColumn()==="ok"?0:2);
  } catch(Throwable $e){exit(2);}
' "$damaged"; then
  echo 'FAIL deliberately damaged disposable copy passed integrity check' >&2; exit 1
fi
echo 'PASS damaged disposable daughter copy is not accepted as recoverable state'

sudo cp "$backup" "$restored"
sudo chown kicom_daughter_ci:kicom_daughter_ci "$restored"
sudo chmod 0600 "$restored"
sudo -u kicom_daughter_ci php -r '
  $root=$argv[1];
  $a=json_decode(file_get_contents($root."/daughter-candidate.json"),true,16,JSON_THROW_ON_ERROR);
  $p=new PDO("sqlite:".$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  if($p->query("PRAGMA quick_check")->fetchColumn()!=="ok")exit(2);
  $row=$p->query("SELECT * FROM genesis WHERE singleton=1")->fetch(PDO::FETCH_ASSOC);
  if(!$row||$row["public_fingerprint"]!==$a["child_public_fingerprint"]
    ||$row["parent_fingerprint"]!==$a["parent_public_fingerprint"]
    ||$row["source_sha256"]!==$a["source_release_sha256"]
    ||$row["candidate_sha256"]!==hash("sha256",file_get_contents($root."/daughter-candidate.json")))exit(3);
' "$root" "$restored"
echo 'PASS independently restored daughter SQLite has intact identity and package binding'

test "$(sudo -u kicom_inner_ci cat "$root/interior/state")" = 'mutable-inner-state'
sudo test "$(sudo cat "$root/recovery/anchor")" = 'independent-recovery-root'
sudo -u kicom_daughter_ci test -r "$database"
echo 'PASS recovery experiment preserved original daughter DB, mother state and recovery anchor'
echo 'KICOM_DAUGHTER_RECOVERY_TESTS_PASSED=10'
