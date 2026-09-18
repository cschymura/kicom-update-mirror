<?php
declare(strict_types=1);

require_once __DIR__ . '/SkillCultivation.php';

/**
 * Hardened public facade for Skill Cultivation.
 *
 * V1 is retained as historical implementation evidence. V2 is the integration
 * surface for 0.9.17/g18 and makes the non-negotiable trust boundaries
 * immutable even if a caller supplies conflicting metadata.
 */
final class KiComSkillCultivationV2
{
    public const SCHEMA = 2;

    private const LOCKED_BOUNDARIES = [
        'arbitrary_code_execution' => 'FORBIDDEN',
        'direct_secret_visibility' => 'FORBIDDEN',
        'external_permission_grant' => 'FORBIDDEN',
        'protected_external_action' => 'EXTERNAL_AUTH_REQUIRED',
        'history_hard_delete' => 'FORBIDDEN',
    ];

    private KiComSkillCultivation $engine;

    public function __construct(string $root)
    {
        $this->engine = new KiComSkillCultivation($root);
    }

    public function status(): array
    {
        $status = $this->engine->status();
        $status['facade_schema'] = self::SCHEMA;
        $status['boundary_policy'] = 'immutable-non-negotiable-boundaries';
        return $status;
    }

    public function observeGap(
        string $kind,
        string $title,
        string $description,
        string $source,
        array $boundaries = [],
        array $successCriteria = []
    ): array {
        $result = $this->engine->observeGap(
            $kind,
            $title,
            $description,
            $source,
            $this->hardenBoundaries($boundaries),
            $successCriteria
        );
        return $this->hardenResult($result);
    }

    public function recordEpisode(
        string $skillId,
        string $context,
        bool $success,
        bool $contained,
        bool $rollbackOk,
        string $evidence,
        array $metrics = []
    ): array {
        return $this->hardenResult($this->engine->recordEpisode(
            $skillId,
            $context,
            $success,
            $contained,
            $rollbackOk,
            $evidence,
            $metrics
        ));
    }

    public function markDegraded(string $skillId, string $reason, string $source): array
    {
        return $this->hardenResult($this->engine->markDegraded($skillId, $reason, $source));
    }

    public function supersede(string $skillId, string $replacementId, string $reason): array
    {
        return $this->hardenResult($this->engine->supersede($skillId, $replacementId, $reason));
    }

    public function getSkill(string $skillId): ?array
    {
        $skill = $this->engine->getSkill($skillId);
        return is_array($skill) ? $this->hardenSkill($skill) : null;
    }

    public function capabilities(): array
    {
        $out = [];
        foreach ($this->engine->capabilities() as $capability) {
            if (!is_array($capability)) continue;
            $capability['boundaries'] = $this->hardenBoundaries(
                is_array($capability['boundaries'] ?? null) ? $capability['boundaries'] : []
            );
            $out[] = $capability;
        }
        return $out;
    }

    public static function lockedBoundaries(): array
    {
        return self::LOCKED_BOUNDARIES;
    }

    private function hardenResult(array $result): array
    {
        if (is_array($result['skill'] ?? null)) {
            $result['skill'] = $this->hardenSkill($result['skill']);
        }
        return $result;
    }

    private function hardenSkill(array $skill): array
    {
        $skill['boundaries'] = $this->hardenBoundaries(
            is_array($skill['boundaries'] ?? null) ? $skill['boundaries'] : []
        );
        return $skill;
    }

    private function hardenBoundaries(array $boundaries): array
    {
        $safe = [];
        foreach ($boundaries as $key => $value) {
            $k = trim((string)$key);
            $v = strtoupper(trim((string)$value));
            if ($k === '' || strlen($k) > 80 || !preg_match('/^[A-Za-z0-9._:-]+$/', $k)) continue;
            if (array_key_exists($k, self::LOCKED_BOUNDARIES)) continue;
            if (!in_array($v, ['AVAILABLE','DEGRADED','UNKNOWN','FORBIDDEN','STALE','EXTERNAL_AUTH_REQUIRED'], true)) continue;
            $safe[$k] = $v;
        }
        foreach (self::LOCKED_BOUNDARIES as $key => $value) {
            $safe[$key] = $value;
        }
        ksort($safe);
        return $safe;
    }
}
