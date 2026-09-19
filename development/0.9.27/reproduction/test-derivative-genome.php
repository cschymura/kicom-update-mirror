<?php
declare(strict_types=1);
require __DIR__ . '/KiComDerivativeGenome.php';

$checks = 0;
$ok = static function (bool $v, string $m) use (&$checks): void {
    $checks++;
    if (!$v) { fwrite(STDERR, "FAIL $checks: $m\n"); exit(1); }
};
$parent = json_decode(file_get_contents(__DIR__ . '/../../../source/0.9.26-r3/genome/genome.json'), true, 512, JSON_THROW_ON_ERROR);
$kp = sodium_crypto_sign_keypair();
$pub = sodium_crypto_sign_publickey($kp);
$other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
$nonce = bin2hex(random_bytes(32));
$child = KiComDerivativeGenome::build($parent, base64_encode($pub), $nonce);

$ok($child['id'] !== $parent['id'], 'child id must differ');
$ok($child['parent'] === KiComDerivativeGenome::SOURCE_GENOME_ID, 'parent lineage');
$ok($child['generation'] === 26, 'generation increment');
$ok(KiComDerivativeGenome::verify($child, base64_encode($pub)), 'valid binding');
$ok(!KiComDerivativeGenome::verify($child, base64_encode($other)), 'other key rejected');
$ok($child['derivative_identity']['authority_granted'] === false, 'no authority');
$ok($child['derivative_identity']['deployment_permitted'] === false, 'no deployment');
$ok($child['derivative_identity']['source_package_sha256'] === KiComDerivativeGenome::SOURCE_PACKAGE_SHA256, 'R3 package pinned');

$t = $child; $t['derivative_identity']['source_package_sha256'] = str_repeat('0', 64);
$ok(!KiComDerivativeGenome::verify($t, base64_encode($pub)), 'package substitution rejected');
$t = $child; $t['id'] = 'kicom-child-g26-fake';
$ok(!KiComDerivativeGenome::verify($t, base64_encode($pub)), 'text-only id substitution rejected');
$t = $child; $t['derivative_identity']['authority_granted'] = true;
$ok(!KiComDerivativeGenome::verify($t, base64_encode($pub)), 'authority escalation rejected');
$t = $child; $t['derivative_identity']['binding_sha256'] = str_repeat('f', 64);
$ok(!KiComDerivativeGenome::verify($t, base64_encode($pub)), 'binding tamper rejected');

$parent2 = $parent; $parent2['id'] = 'kicom-0.9.26-g25r2';
try { KiComDerivativeGenome::build($parent2, base64_encode($pub), $nonce); $ok(false, 'stale parent rejected'); }
catch (InvalidArgumentException) { $ok(true, 'stale parent rejected'); }

$child2 = KiComDerivativeGenome::build($parent, base64_encode($pub), $nonce);
$ok($child2['id'] === $child['id'], 'same host inputs deterministic');
$child3 = KiComDerivativeGenome::build($parent, base64_encode($pub), bin2hex(random_bytes(32)));
$ok($child3['id'] !== $child['id'], 'fresh host nonce changes identity');

fwrite(STDOUT, "OK derivative-genome: {$checks} checks\n");
