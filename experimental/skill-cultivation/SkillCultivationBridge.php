<?php
declare(strict_types=1);
require_once __DIR__ . '/SkillCultivation.php';

/**
 * Structured bridge from Experience/Action evidence into Skill Cultivation.
 *
 * It accepts only explicit event types. Free-form memory cannot grant authority
 * or create permissions. The bridge creates evidence/candidates; it does not
 * execute the skill implementation itself.
 */
final class KiComSkillCultivationBridge
{
    private KiComSkillCultivation $engine;

    public function __construct(KiComSkillCultivation $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Accepted event types:
     * - human_technical_mediation
     * - recurring_action_failure
     * - capability_opportunity
     *
     * @param array<string,mixed> $event
     */
    public function observeExperience(array $event): array
    {
        $type = strtolower(trim((string)($event['type'] ?? '')));
        $capabilityKey = $this->safeKey((string)($event['capability_key'] ?? ''));
        $source = $this->safeText((string)($event['source'] ?? 'experience'), 180);
        if ($capabilityKey === '' || $source === '') {
            return ['ok' => false, 'code' => 'EXPERIENCE_IDENTITY_REQUIRED'];
        }

        return match ($type) {
            'human_technical_mediation' => $this->engine->observeGap(
                'human_technical_mediation',
                $capabilityKey,
                'Repeated technical human mediation indicates a missing or immature internal capability: ' . $capabilityKey,
                $source,
                $this->boundaries($event),
                $this->criteria($event)
            ),
            'recurring_action_failure' => $this->engine->observeGap(
                'recurring_action_failure',
                $capabilityKey,
                'Recurring bounded action failure indicates a capability that should be repaired, practiced or evolved: ' . $capabilityKey,
                $source,
                $this->boundaries($event),
                $this->criteria($event)
            ),
            'capability_opportunity' => $this->engine->observeGap(
                'capability_opportunity',
                $capabilityKey,
                'World/perception evidence indicates a new bounded capability opportunity: ' . $capabilityKey,
                $source,
                $this->boundaries($event),
                $this->criteria($event)
            ),
            default => ['ok' => true, 'code' => 'EXPERIENCE_IGNORED', 'reason' => 'unsupported_event_type'],
        };
    }

    /**
     * Convert a structured action/test result into practice evidence.
     * No outcome can alter protected external permissions.
     *
     * @param array<string,mixed> $result
     */
    public function observePracticeResult(string $skillId, array $result): array
    {
        $context = $this->safeKey((string)($result['context'] ?? ''));
        $evidence = $this->safeText((string)($result['evidence'] ?? ''), 1200);
        if ($context === '' || $evidence === '') {
            return ['ok' => false, 'code' => 'PRACTICE_RESULT_INCOMPLETE'];
        }
        return $this->engine->recordEpisode(
            $skillId,
            $context,
            !empty($result['success']),
            !empty($result['contained']),
            !empty($result['rollback_ok']),
            $evidence,
            is_array($result['metrics'] ?? null) ? $result['metrics'] : []
        );
    }

    /**
     * World/Action-model overlay. This is evidence about capability maturity,
     * never an authority grant.
     *
     * @return array<string,mixed>
     */
    public function capabilityOverlay(): array
    {
        return [
            'schema' => 1,
            'generated_at' => gmdate('c'),
            'authority' => 'evidence-only; never grants external permission',
            'capabilities' => $this->engine->capabilities(),
        ];
    }

    /** @param array<string,mixed> $event @return array<string,string> */
    private function boundaries(array $event): array
    {
        $requested = is_array($event['boundaries'] ?? null) ? $event['boundaries'] : [];
        $safe = [];
        foreach ($requested as $k => $v) {
            $key = $this->safeKey((string)$k);
            $value = strtoupper($this->safeKey((string)$v));
            if ($key === '') continue;
            if (in_array($value, ['AVAILABLE','DEGRADED','UNKNOWN','FORBIDDEN','STALE','EXTERNAL_AUTH_REQUIRED'], true)) {
                $safe[$key] = $value;
            }
        }
        // These cannot be weakened by evidence input.
        $safe['arbitrary_code_execution'] = 'FORBIDDEN';
        $safe['direct_secret_visibility'] = 'FORBIDDEN';
        $safe['external_permission_grant'] = 'FORBIDDEN';
        $safe['protected_external_action'] = 'EXTERNAL_AUTH_REQUIRED';
        $safe['history_hard_delete'] = 'FORBIDDEN';
        return $safe;
    }

    /** @param array<string,mixed> $event @return list<string> */
    private function criteria(array $event): array
    {
        $raw = is_array($event['success_criteria'] ?? null) ? $event['success_criteria'] : [];
        $out = [];
        foreach (array_slice($raw, 0, 16) as $value) {
            if (!is_string($value)) continue;
            $v = $this->safeText($value, 220);
            if ($v !== '') $out[] = $v;
        }
        return array_values(array_unique($out));
    }

    private function safeKey(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 96) return '';
        return preg_match('/^[A-Za-z0-9._:-]+$/', $value) ? $value : '';
    }

    private function safeText(string $value, int $max): string
    {
        $value = trim(str_replace(["\0", "\r"], ['', ''], $value));
        if (strlen($value) > $max) $value = substr($value, 0, $max);
        return $value;
    }
}
