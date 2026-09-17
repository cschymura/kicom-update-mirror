<?php
declare(strict_types=1);
require_once __DIR__ . '/SkillCultivation.php';

function must(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "SELFTEST_FAIL {$message}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/kicom-skill-cultivation-' . bin2hex(random_bytes(6));
$engine = new KiComSkillCultivation($root);

$s0 = $engine->status();
must($s0['ok'] === true, 'initial status');
must($s0['skills'] === 0, 'initial skill count');

// First human intervention is evidence; second recurrence becomes a cultivation candidate.
$r1 = $engine->observeGap(
    'human_technical_mediation',
    'resilient artifact delivery',
    'A human had to move an update artifact between known KiCom endpoints.',
    'experience:test-1',
    [],
    ['deliver exact SHA-bound bytes', 'verify destination result', 'fall back without weakening trust']
);
must($r1['ok'] === true, 'first gap accepted');
must($r1['skill']['state'] === KiComSkillCultivation::STATE_OBSERVED_GAP, 'first gap remains observed');
$id = $r1['skill']['id'];

$r2 = $engine->observeGap(
    'human_technical_mediation',
    'resilient artifact delivery',
    'A human had to move an update artifact between known KiCom endpoints.',
    'experience:test-2'
);
must($r2['ok'] === true, 'second gap accepted');
must($r2['skill']['state'] === KiComSkillCultivation::STATE_PROPOSED, 'second recurrence proposes cultivation');
must($r2['skill']['human_mediation_observations'] === 2, 'mediation count');

// Permission boundaries are intrinsic and cannot be turned into capability grants by skill evidence.
$skill = $engine->getSkill($id);
must(is_array($skill), 'skill readable');
must($skill['boundaries']['external_permission_grant'] === 'FORBIDDEN', 'permission grant forbidden');
must($skill['boundaries']['protected_external_action'] === 'EXTERNAL_AUTH_REQUIRED', 'external auth boundary');
must($skill['boundaries']['history_hard_delete'] === 'FORBIDDEN', 'no hard delete');

// Practice across two bounded contexts. Eight episodes with >=85% success matures to AVAILABLE.
$contexts = ['sandbox', 'testportal'];
for ($i = 0; $i < 8; $i++) {
    $e = $engine->recordEpisode(
        $id,
        $contexts[$i % 2],
        true,
        true,
        true,
        'sha-bound artifact delivered and destination healthcheck passed',
        ['latency_ms' => 100 + $i, 'verified' => true]
    );
    must($e['ok'] === true, 'episode ' . $i);
}
$skill = $engine->getSkill($id);
must($skill['state'] === KiComSkillCultivation::STATE_AVAILABLE, 'skill matured to available');
must($skill['episodes'] === 8, 'episode count');
must(count($skill['contexts']) === 2, 'context diversity');

$caps = $engine->capabilities();
must(count($caps) === 1, 'one capability');
must($caps[0]['availability'] === 'AVAILABLE', 'capability available');

// A later operational problem degrades instead of deleting evidence.
$d = $engine->markDegraded($id, 'destination contract changed', 'observer:selftest');
must($d['ok'] === true, 'degrade accepted');
must($d['skill']['state'] === KiComSkillCultivation::STATE_DEGRADED, 'degraded state');

// Create a replacement skill and supersede the old one. History must remain append-only.
$n1 = $engine->observeGap(
    'capability_improvement',
    'resilient artifact delivery v2',
    'A better transport strategy is available with signed channel discovery.',
    'evolution:selftest'
);
$n2 = $engine->observeGap(
    'capability_improvement',
    'resilient artifact delivery v2',
    'A better transport strategy is available with signed channel discovery.',
    'evolution:selftest-2'
);
must($n1['ok'] && $n2['ok'], 'replacement observed');
$replacement = $n2['skill']['id'];
$sp = $engine->supersede($id, $replacement, 'new generation selected');
must($sp['ok'] === true, 'supersede accepted');
must($sp['skill']['state'] === KiComSkillCultivation::STATE_SUPERSEDED, 'superseded state');
must($sp['skill']['superseded_by'] === $replacement, 'replacement linkage');

$final = $engine->status();
must($final['skills'] === 2, 'history preserves both generations');
must($final['history_entries'] >= 13, 'append-only evidence retained');

// No execution primitive exists in the public surface.
$methods = array_map(static fn(ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(KiComSkillCultivation::class))->getMethods(ReflectionMethod::IS_PUBLIC));
foreach (['exec', 'shell', 'runCode', 'grantPermission', 'deleteHistory'] as $forbiddenMethod) {
    must(!in_array($forbiddenMethod, $methods, true), 'forbidden public primitive ' . $forbiddenMethod);
}

echo "SKILL_CULTIVATION_SELFTEST_OK\n";
echo json_encode($final, JSON_UNESCAPED_SLASHES) . "\n";
