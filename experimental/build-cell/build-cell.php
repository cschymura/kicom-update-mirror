<?php
declare(strict_types=1);

require_once __DIR__.'/BuildCellVerifier.php';

$root = $argv[1] ?? dirname(__DIR__, 2);
$mode = strtolower((string)($argv[2] ?? 'verify'));

$result = KiComBuildCellVerifier::verify($root);
$result['composer'] = KiComBuildCellVerifier::composerPolicy($root);
$result['mode'] = $mode;
$result['generated_at'] = gmdate('c');
$result['runtime'] = [
    'php'=>PHP_VERSION,
    'sapi'=>PHP_SAPI,
    'os'=>PHP_OS_FAMILY,
];

$out = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($out)) {
    fwrite(STDERR, "build-cell report encoding failed\n");
    exit(2);
}

$report = getenv('KICOM_BUILD_CELL_REPORT');
if (is_string($report) && $report !== '') {
    if (@file_put_contents($report, $out."\n", LOCK_EX) === false) {
        fwrite(STDERR, "build-cell report write failed\n");
        exit(2);
    }
}

echo $out, "\n";
exit(!empty($result['ok']) && !empty($result['composer']['ok']) ? 0 : 1);
