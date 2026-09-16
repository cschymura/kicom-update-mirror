<?php
declare(strict_types=1);

require_once __DIR__.'/BuildCellVerifier.php';

function bcAssert(bool $ok, string $label): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$base = sys_get_temp_dir().'/kicom-build-cell-selftest-'.bin2hex(random_bytes(4));
@mkdir($base.'/good/src', 0700, true);
file_put_contents($base.'/good/src/Test.php', "<?php\ndeclare(strict_types=1);\nfunction x(): int { return 1; }\n");
file_put_contents($base.'/good/config.json', "{\"ok\":true}\n");
file_put_contents($base.'/good/composer.json', json_encode([
    'name'=>'kicom/selftest',
    'require'=>['php'=>'>=8.0'],
    'scripts'=>['test'=>'php -v'],
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

$r = KiComBuildCellVerifier::verify($base.'/good');
bcAssert(!empty($r['ok']), 'valid tree passes');
bcAssert(($r['files'] ?? 0) === 3, 'inventory count');
bcAssert(preg_match('/^[a-f0-9]{64}$/', (string)($r['tree_sha256'] ?? '')) === 1, 'tree hash');
$c = KiComBuildCellVerifier::composerPolicy($base.'/good');
bcAssert(!empty($c['ok']) && !empty($c['declares_scripts']), 'composer scripts detected');
bcAssert(($c['install_policy'] ?? '') === '--no-scripts --no-plugins', 'composer safe policy');

@mkdir($base.'/bad', 0700, true);
file_put_contents($base.'/bad/Broken.php', "<?php function broken( {\n");
file_put_contents($base.'/bad/bad.json', "{ nope }\n");
file_put_contents($base.'/bad/.env', "SECRET=dont-store\n");
$r2 = KiComBuildCellVerifier::verify($base.'/bad');
bcAssert(empty($r2['ok']), 'invalid tree fails');
$codes = array_column((array)($r2['errors'] ?? []), 'code');
bcAssert(in_array('PHP_SYNTAX_INVALID', $codes, true), 'php syntax caught');
bcAssert(in_array('JSON_INVALID', $codes, true), 'json syntax caught');
bcAssert(in_array('SECRET_MATERIAL_FORBIDDEN', $codes, true), 'secret material caught');

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    if ($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname());
}
@rmdir($base);

echo "BUILD CELL SELFTEST PASS\n";
