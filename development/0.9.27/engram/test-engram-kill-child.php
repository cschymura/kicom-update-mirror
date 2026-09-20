<?php
declare(strict_types=1);
/**
 * SYNTHETIC CI TEST CHILD ONLY. Exits only after the parent process sends
 * SIGKILL at a deterministic rebuild phase. NEVER deploy as a web route.
 */
require_once __DIR__ . '/KiComEngramMirrorSet.php';
if (PHP_SAPI !== 'cli' || $argc !== 5
    || !preg_match('/\Aengram-kill-synthetic-[a-f0-9]{24}\z/D', basename($argv[1]))
    || !preg_match('/\Amirror-[a-f0-9]{32}\.json\z/D', $argv[2])
    || !preg_match('/\A[a-f0-9]{64}\z/D', $argv[3])
    || !in_array($argv[4],[
        'before-first-copy','during-first-copy','after-first-copy',
        'during-second-copy','after-second-copy',
        'before-manifest-publish','after-manifest-publish'
    ],true)) {
    exit(64);
}
$root = $argv[1];
$phaseToKill = $argv[4];
$raid = new KiComEngramMirrorSet(
    $root.'/a',$root.'/b',$root.'/manifests',$root.'/web'
);
$raid->rebuildNewGeneration($argv[2],$argv[3],
    static function (string $phase) use ($phaseToKill): void {
        if ($phase === $phaseToKill) {
            fwrite(STDOUT, "READY_TO_KILL ".$phase."\n");
            fflush(STDOUT);
            // Parent terminates this real subprocess using SIGKILL.
            usleep(15000000);
            exit(67); // Parent did not terminate: fail the test.
        }
    }
);
exit(68); // Unexpected success before fault marker.
