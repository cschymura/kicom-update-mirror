<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPamKclAdapter.php';
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "PDO_SQLITE_REQUIRED\n");
    exit(2);
}
$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    ++$count;
    if (!$ok) { throw new RuntimeException('FAIL '.$label); }
    echo 'PASS '.$label.PHP_EOL;
}
function mustReject(callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException|RuntimeException $e) { check(true, $label); return; }
    check(false, $label);
}
$now = 1800000000;
$db = new PDO('sqlite::memory:');
$pam = new KiComPam($db, static function () use (&$now): int { return $now; });
$adapter = new KiComPamKclAdapter($pam);
$responses = [
    'HELLO' => "KCL/1\nOK hello\nFACT request_id=\"random-A\"\nFACT version=\"0.9.25\"\nEND\n",
    'GENOME_STATUS' => "KCL/1\nOK living_status\nFACT request_id=\"random-B\"\nFACT version=\"0.9.25\"\nFACT healthy=true\nFACT trusted=true\nFACT lkg_ok=true\nFACT drift_count=0\nFACT unknown_count=0\nEND\n",
    'SQLITE_STATUS' => "KCL/1\nOK sqlite_status\nFACT request_id=\"random-C\"\nFACT primary=true\nFACT quick_check=\"ok\"\nFACT journal_mode=\"wal\"\nEND\n",
    'UPDATE_STATUS' => "KCL/1\nOK update_status\nFACT request_id=\"random-D\"\nFACT pending_version=\"0.9.26\"\nFACT pending_risk=\"red\"\nFACT pending_source=\"pull:mirror\"\nEND\n",
];
$first = $adapter->capture('night-1', $responses);
check($first['genome_healthy'] && $first['sqlite_quick_check'], 'Healthy fixed-endpoint KCL mapped to observations');
check($first['protected_install_pending'], 'RED protected action recognized');
check($pam->perceive('KiCom:0.9.25', 'production-install')['state'] === 'FORBIDDEN', 'Protected install remains denied by PAM');
check((int) $db->query("SELECT COUNT(*) FROM pam_actions WHERE boundary='internal'")->fetchColumn() === 1,
    'Release review is internal work, not production execution');
check((int) $db->query("SELECT COUNT(*) FROM pam_actions WHERE boundary='protected-external'")->fetchColumn() === 0,
    'Adapter does not enqueue a production installation');
$responses['HELLO'] = str_replace('random-A', 'random-OTHER', $responses['HELLO']);
$second = $adapter->capture('night-2', $responses);
check($first['state_sha256'] === $second['state_sha256'], 'Transient request IDs do not invalidate semantic state');
check($pam->latestCheckpoint()['run_key'] === 'night-2', 'Next scheduled run can read latest checkpoint');
check((int) $db->query('SELECT COUNT(*) FROM pam_actions')->fetchColumn() === 1, 'Same release review is idempotent');
$responses['GENOME_STATUS'] = str_replace('FACT healthy=true', 'FACT healthy=false', $responses['GENOME_STATUS']);
$third = $adapter->capture('night-3', $responses);
check(!$third['genome_healthy'] && $pam->perceive('KiCom:0.9.25', 'genome-integrity')['state'] === 'DEGRADED',
    'Real health degradation is recorded');
$responses['GENOME_STATUS'] = str_replace('FACT healthy=false', 'FACT healthy=true', $responses['GENOME_STATUS']);
$responses['UPDATE_STATUS'] = "KCL/1\nERROR protected\nEND\n";
mustReject(fn() => $adapter->capture('night-4', $responses), 'Error response never interpreted as healthy');
$responses['UPDATE_STATUS'] = "KCL/1\nOK update_status\nFACT pending_version=\"0.9.26\"\nFACT pending_version=\"0.9.27\"\nEND\n";
mustReject(fn() => $adapter->capture('night-4', $responses), 'Ambiguous duplicate fact rejected');
$responses['UPDATE_STATUS'] = "KCL/1\nOK update_status\nFACT pending_version=\"0.9.26\"\nEND\n";

unset($responses['ARBITRARY_URL']);
$responses['UPDATE_STATUS'] = "KCL/1\nOK hello\nFACT pending_version=\"0.9.26\"\nEND\n";
mustReject(fn() => $adapter->capture('night-5', $responses),
    'Successful response from the wrong endpoint is rejected');
$responses['UPDATE_STATUS'] = "KCL/1\nOK update_status\nFACT pending_version=\"0.9.26\"\n";
mustReject(fn() => $adapter->capture('night-5', $responses),
    'Truncated response lacking final terminator is rejected');
$responses['UPDATE_STATUS'] = "KCL/1\nOK update_status\nFACT pending_version=\"0.9.26\"\nEND\nEXTRA";
mustReject(fn() => $adapter->capture('night-5', $responses),
    'Trailing content after the final terminator is rejected');
$responses['ARBITRARY_URL'] = "KCL/1\nOK hello\nEND\n";
mustReject(fn() => $adapter->capture('night-4', $responses), 'Unapproved endpoint evidence rejected');

// Post-install 0.9.26 R3 must be independently re-observed. A stale text
// PROJECT_STATE from a previous generation is not sufficient runtime proof.
$installed = [
    'HELLO' => "KCL/1\nOK hello\nFACT version=\"0.9.26\"\nEND\n",
    'GENOME_STATUS' => "KCL/1\nOK living_status\nFACT version=\"0.9.26\"\nFACT genome_id=\"kicom-0.9.26-g25r3\"\nFACT healthy=true\nFACT trusted=true\nFACT lkg_ok=true\nFACT drift_count=0\nFACT unknown_count=0\nEND\n",
    'SQLITE_STATUS' => "KCL/1\nOK sqlite_status\nFACT primary=true\nFACT quick_check=\"ok\"\nFACT journal_mode=\"wal\"\nEND\n",
    'UPDATE_STATUS' => "KCL/1\nOK update_status\nFACT pending_version=\"\"\nFACT pending_risk=\"\"\nFACT pending_source=\"\"\nEND\n",
];
$post = $adapter->capture('postinstall-r3-1', $installed);
check($post['genome_healthy'] && $post['sqlite_quick_check']
    && $post['genome_id'] === 'kicom-0.9.26-g25r3',
    'Installed R3 identity is confirmed only by matching live genome evidence');
check($post['pending_consistent'] && !$post['protected_install_pending'],
    'Empty post-install pending update is internally consistent');
check($pam->perceive('KiCom:0.9.26', 'production-install')['state'] === 'UNKNOWN',
    'Absence of pending package does not grant production installation authority');
check($pam->perceive('KiCom:0.9.26', 'pending-update-consistency')['state'] === 'AVAILABLE',
    'Consistent empty pending state is stored as short-lived evidence');
$beforeActions = (int)$db->query('SELECT COUNT(*) FROM pam_actions')->fetchColumn();
$installed['GENOME_STATUS'] = str_replace('g25r3', 'g25r2', $installed['GENOME_STATUS']);
$wrongLineage = $adapter->capture('postinstall-wrong-genome', $installed);
check(!$wrongLineage['genome_healthy']
    && $pam->perceive('KiCom:0.9.26', 'genome-integrity')['state'] === 'DEGRADED',
    'Legacy canonical text cannot substitute for exact R3 genome lineage');
$installed['GENOME_STATUS'] = str_replace('g25r2', 'g25r3', $installed['GENOME_STATUS']);
$installed['UPDATE_STATUS'] = "KCL/1\nOK update_status\nFACT pending_version=\"\"\nFACT pending_risk=\"red\"\nFACT pending_source=\"\"\nEND\n";
$inconsistent = $adapter->capture('postinstall-pending-contradiction', $installed);
check(!$inconsistent['pending_consistent']
    && $pam->perceive('KiCom:0.9.26', 'pending-update-consistency')['state'] === 'DEGRADED',
    'Contradictory post-install pending metadata never becomes a clean checkpoint');
check((int)$db->query('SELECT COUNT(*) FROM pam_actions')->fetchColumn() === $beforeActions,
    'Installed healthy state and inconsistent pending state enqueue no install or review actions');

echo "PAM_KCL_TESTS_PASSED=$count\n";
