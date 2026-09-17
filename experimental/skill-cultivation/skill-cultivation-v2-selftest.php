<?php
declare(strict_types=1);

require_once __DIR__ . '/SkillCultivationV2.php';

function mustV2(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "SELFTEST_FAIL {$message}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/kicom-skill-cultivation-v2-' . bin2hex(random_bytes(6));
$engine = new KiComSkillCultivationV2($root);

$result = $engine->observeGap(
    'human_technical_mediation',
    'immutable boundary probe',
    'A caller attempts to weaken non-negotiable trust boundaries.',
    'selftest:v2',
    [
        'external_permission_grant' => 'AVAILABLE',
        'protected_external_action' => 'AVAILABLE',
        'history_hard_delete' => 'AVAILABLE',
        'direct_secret_visibility' => 'AVAILABLE',
        'arbitrary_code_execution' => 'AVAILABLE',
        'internal_test_capability' => 'DEGRADED',
    ]
);

mustV2($result['ok'] === true, 'gap accepted');
$boundaries = $result['skill']['boundaries'];
mustV2($boundaries['external_permission_grant'] === 'FORBIDDEN', 'external grant remains forbidden');
mustV2($boundaries['protected_external_action'] === 'EXTERNAL_AUTH_REQUIRED', 'external action remains protected');
mustV2($boundaries['history_hard_delete'] === 'FORBIDDEN', 'history deletion remains forbidden');
mustV2($boundaries['direct_secret_visibility'] === 'FORBIDDEN', 'secret visibility remains forbidden');
mustV2($boundaries['arbitrary_code_execution'] === 'FORBIDDEN', 'arbitrary code remains forbidden');
mustV2($boundaries['internal_test_capability'] === 'DEGRADED', 'non-locked bounded metadata retained');

$locked = KiComSkillCultivationV2::lockedBoundaries();
mustV2(count($locked) === 5, 'five locked boundaries');

$status = $engine->status();
mustV2(($status['facade_schema'] ?? 0) === 2, 'facade schema');
mustV2(($status['boundary_policy'] ?? '') === 'immutable-non-negotiable-boundaries', 'boundary policy');

echo "SKILL_CULTIVATION_V2_SELFTEST_OK\n";
echo json_encode($status, JSON_UNESCAPED_SLASHES) . "\n";
