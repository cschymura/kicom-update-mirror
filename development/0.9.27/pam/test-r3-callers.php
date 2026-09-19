<?php
declare(strict_types=1);

/**
 * Static, exact-R3 caller inventory regression.
 * Executed ONLY against a disposable, checksum-verified R3 source tree.
 * New direct callers must cause an explicit audit, not bypass the sequencer.
 */
$root = realpath($argv[1] ?? '');
$expected = realpath(sys_get_temp_dir().'/kicom-pam-r3-runtime');
if ($root === false || $root !== $expected
    || !is_file($root.'/SOURCE-SNAPSHOT.json')) {
    throw new RuntimeException('Isolated R3 source tree required');
}
$lib = (string)file_get_contents($root.'/lib.php');
$index = (string)file_get_contents($root.'/index.php');
$checks = 0;
function inventoryOk(bool $ok,string $name):void {
    global $checks;
    ++$checks;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name.PHP_EOL;
}
$counts = [
    'kicomSqliteSnapshot' => [4,1],
    'kicomSqliteLatestSnapshot' => [4,0],
    'kicomSqliteRecover' => [2,1],
    'kicomSqliteMaintenanceTick' => [1,2],
    'kicomSqliteEvolutionTick' => [2,1]
];
foreach ($counts as $fn=>$expectedCount) {
    $a = preg_match_all('/\b'.preg_quote($fn,'/').'\s*\(/',$lib);
    $b = preg_match_all('/\b'.preg_quote($fn,'/').'\s*\(/',$index);
    inventoryOk($a===$expectedCount[0] && $b===$expectedCount[1],
        $fn.' native lib/index call count matches reviewed R3 graph');
}
inventoryOk(str_contains($lib,"kicomSqliteSnapshot('pre-evolution-'")
    && str_contains($lib,"kicomSqliteSnapshot('post-evolution-'")
    && str_contains($lib,"kicomSqliteSnapshot('automatic-daily'"),
    'Evolution and scheduled-maintenance native snapshot writers found');
inventoryOk(str_contains($index,"kicomSqliteSnapshot((string)(")
    && str_contains($index,"case 'SQLITE_SNAPSHOT':"),
    'Manual KCL native snapshot writer found');
inventoryOk(str_contains($lib,"kicomSqliteRecover('automatic-health')")
    && str_contains($index,"kicomSqliteRecover('manual')"),
    'Automatic and manual recovery callers found');
inventoryOk(str_contains($lib,'$snap=kicomSqliteLatestSnapshot();kicomSqliteDb(true);$stamp=')
    && str_contains($lib,"@rename($s,kicomSqliteQuarantineDir()"),
    'Native R3 recovery still performs candidate selection before quarantine');
inventoryOk(str_contains($lib,'rsort($a,SORT_STRING);return $a;')
    && str_contains($lib,"gmdate('YmdHis').'-'.substr(hash('sha256',uniqid('',true)),0,10)"),
    'Legacy snapshot ID includes second and random suffix; lexicographic sort is unproven');
echo "PAM_R3_CALLER_INVENTORY_PASSED=$checks\n";
