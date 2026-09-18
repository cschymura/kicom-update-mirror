<?php
declare(strict_types=1);

/**
 * KiCom Skill Cultivation v1
 *
 * Converts recurring capability gaps and opportunities into evidence-backed
 * capabilities. This layer deliberately does NOT execute arbitrary code,
 * grant permissions, expose secrets, or cross protected external boundaries.
 * Persistent evidence is append-only; derived state may be superseded but is
 * never used to erase history.
 */
final class KiComSkillCultivation
{
    public const SCHEMA = 1;

    public const STATE_OBSERVED_GAP = 'observed_gap';
    public const STATE_PROPOSED = 'proposed';
    public const STATE_EXPERIMENTING = 'experimenting';
    public const STATE_PRACTICED = 'practiced';
    public const STATE_RELIABLE = 'reliable';
    public const STATE_AVAILABLE = 'available';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_SUPERSEDED = 'superseded';

    private string $root;
    private string $stateFile;
    private string $historyFile;

    public function __construct(string $root)
    {
        $root = rtrim($root, '/');
        if ($root === '') {
            throw new InvalidArgumentException('SKILL_ROOT_REQUIRED');
        }
        $this->root = $root;
        $this->stateFile = $root . '/skills.json';
        $this->historyFile = $root . '/history.jsonl';
        $this->ensureStorage();
    }

    public function status(): array
    {
        $state = $this->loadState();
        $counts = [];
        foreach ($state['skills'] as $skill) {
            $s = (string)($skill['state'] ?? self::STATE_OBSERVED_GAP);
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        ksort($counts);
        return [
            'ok' => true,
            'schema' => self::SCHEMA,
            'policy' => 'cultivate-capabilities-with-evidence; archive-never-hard-delete',
            'skills' => count($state['skills']),
            'states' => $counts,
            'history_entries' => $this->historyCount(),
        ];
    }

    /**
     * Observe a capability gap or opportunity.
     * Repeated technical human mediation is promoted to a cultivation candidate
     * on the second independent observation.
     */
    public function observeGap(
        string $kind,
        string $title,
        string $description,
        string $source,
        array $boundaries = [],
        array $successCriteria = []
    ): array {
        $kind = $this->token($kind, 64);
        $title = $this->text($title, 180);
        $description = $this->text($description, 1200);
        $source = $this->text($source, 180);
        if ($kind === '' || $title === '' || $description === '' || $source === '') {
            return ['ok' => false, 'code' => 'GAP_FIELDS_REQUIRED'];
        }

        $state = $this->loadState();
        $fingerprint = hash('sha256', $kind . "\n" . mb_strtolower($title) . "\n" . mb_strtolower($description));
        $id = 'skill-' . substr($fingerprint, 0, 20);
        $now = gmdate('c');

        $idx = $this->findSkillIndex($state, $id);
        if ($idx === null) {
            $skill = [
                'id' => $id,
                'fingerprint' => $fingerprint,
                'kind' => $kind,
                'title' => $title,
                'description' => $description,
                'state' => self::STATE_OBSERVED_GAP,
                'observations' => 0,
                'human_mediation_observations' => 0,
                'episodes' => 0,
                'successes' => 0,
                'failures' => 0,
                'safe_failures' => 0,
                'contexts' => [],
                'boundaries' => $this->normalizeBoundaries($boundaries),
                'success_criteria' => $this->normalizeStrings($successCriteria, 16, 220),
                'created_at' => $now,
                'updated_at' => $now,
                'last_observed_at' => $now,
                'last_episode_at' => null,
                'last_failure_at' => null,
                'maturity_reason' => 'first_observation',
            ];
            $state['skills'][] = $skill;
            $idx = count($state['skills']) - 1;
        }

        $skill = $state['skills'][$idx];
        $skill['observations'] = (int)$skill['observations'] + 1;
        if ($kind === 'human_technical_mediation') {
            $skill['human_mediation_observations'] = (int)$skill['human_mediation_observations'] + 1;
        }
        $skill['last_observed_at'] = $now;
        $skill['updated_at'] = $now;

        if (
            $skill['state'] === self::STATE_OBSERVED_GAP &&
            ($skill['observations'] >= 2 || $skill['human_mediation_observations'] >= 2)
        ) {
            $skill['state'] = self::STATE_PROPOSED;
            $skill['maturity_reason'] = 'repeated_gap_requires_capability_cultivation';
        }

        $state['skills'][$idx] = $skill;
        $state['updated_at'] = $now;
        $this->saveState($state);
        $this->appendHistory('gap_observed', $id, [
            'kind' => $kind,
            'source' => $source,
            'observation_count' => $skill['observations'],
            'state' => $skill['state'],
        ]);

        return ['ok' => true, 'code' => 'GAP_OBSERVED', 'skill' => $skill];
    }

    /**
     * Record a bounded practice/test episode.
     * `context` identifies the environment class, not secret connection data.
     */
    public function recordEpisode(
        string $skillId,
        string $context,
        bool $success,
        bool $contained,
        bool $rollbackOk,
        string $evidence,
        array $metrics = []
    ): array {
        $skillId = $this->token($skillId, 96);
        $context = $this->token($context, 80);
        $evidence = $this->text($evidence, 1200);
        if ($skillId === '' || $context === '' || $evidence === '') {
            return ['ok' => false, 'code' => 'EPISODE_FIELDS_REQUIRED'];
        }

        $state = $this->loadState();
        $idx = $this->findSkillIndex($state, $skillId);
        if ($idx === null) {
            return ['ok' => false, 'code' => 'SKILL_NOT_FOUND'];
        }

        $skill = $state['skills'][$idx];
        if ($skill['state'] === self::STATE_SUPERSEDED) {
            return ['ok' => false, 'code' => 'SKILL_SUPERSEDED'];
        }

        $now = gmdate('c');
        $skill['episodes'] = (int)$skill['episodes'] + 1;
        $skill['last_episode_at'] = $now;
        $skill['updated_at'] = $now;
        $skill['contexts'][$context] = ((int)($skill['contexts'][$context] ?? 0)) + 1;

        if ($success && $contained) {
            $skill['successes'] = (int)$skill['successes'] + 1;
        } else {
            $skill['failures'] = (int)$skill['failures'] + 1;
            $skill['last_failure_at'] = $now;
            if ($contained && $rollbackOk) {
                $skill['safe_failures'] = (int)$skill['safe_failures'] + 1;
            }
        }

        $evaluation = $this->evaluateMaturity($skill);
        $skill['state'] = $evaluation['state'];
        $skill['maturity_reason'] = $evaluation['reason'];
        $state['skills'][$idx] = $skill;
        $state['updated_at'] = $now;
        $this->saveState($state);

        $this->appendHistory('practice_episode', $skillId, [
            'context' => $context,
            'success' => $success,
            'contained' => $contained,
            'rollback_ok' => $rollbackOk,
            'evidence' => $evidence,
            'metrics' => $this->sanitizeMetrics($metrics),
            'state_after' => $skill['state'],
        ]);

        return ['ok' => true, 'code' => 'EPISODE_RECORDED', 'skill' => $skill, 'evaluation' => $evaluation];
    }

    /** Mark a previously available skill as degraded without deleting evidence. */
    public function markDegraded(string $skillId, string $reason, string $source): array
    {
        return $this->transitionWithEvidence($skillId, self::STATE_DEGRADED, 'skill_degraded', $reason, $source);
    }

    /** Supersede a skill with a newer skill; history is retained. */
    public function supersede(string $skillId, string $replacementId, string $reason): array
    {
        $replacementId = $this->token($replacementId, 96);
        if ($replacementId === '') {
            return ['ok' => false, 'code' => 'REPLACEMENT_REQUIRED'];
        }
        $state = $this->loadState();
        $idx = $this->findSkillIndex($state, $skillId);
        $ridx = $this->findSkillIndex($state, $replacementId);
        if ($idx === null || $ridx === null || $idx === $ridx) {
            return ['ok' => false, 'code' => 'SUPERSEDE_TARGET_INVALID'];
        }
        $now = gmdate('c');
        $state['skills'][$idx]['state'] = self::STATE_SUPERSEDED;
        $state['skills'][$idx]['superseded_by'] = $replacementId;
        $state['skills'][$idx]['maturity_reason'] = $this->text($reason, 400);
        $state['skills'][$idx]['updated_at'] = $now;
        $state['updated_at'] = $now;
        $this->saveState($state);
        $this->appendHistory('skill_superseded', $skillId, ['replacement_id' => $replacementId, 'reason' => $reason]);
        return ['ok' => true, 'code' => 'SKILL_SUPERSEDED', 'skill' => $state['skills'][$idx]];
    }

    public function getSkill(string $skillId): ?array
    {
        $state = $this->loadState();
        $idx = $this->findSkillIndex($state, $skillId);
        return $idx === null ? null : $state['skills'][$idx];
    }

    public function capabilities(): array
    {
        $state = $this->loadState();
        $out = [];
        foreach ($state['skills'] as $skill) {
            $s = (string)($skill['state'] ?? '');
            $availability = match ($s) {
                self::STATE_AVAILABLE => 'AVAILABLE',
                self::STATE_RELIABLE, self::STATE_PRACTICED, self::STATE_EXPERIMENTING, self::STATE_PROPOSED => 'DEGRADED',
                self::STATE_DEGRADED => 'DEGRADED',
                self::STATE_SUPERSEDED => 'STALE',
                default => 'UNKNOWN',
            };
            $out[] = [
                'skill_id' => $skill['id'],
                'title' => $skill['title'],
                'state' => $s,
                'availability' => $availability,
                'boundaries' => $skill['boundaries'],
                'episodes' => $skill['episodes'],
                'successes' => $skill['successes'],
                'failures' => $skill['failures'],
            ];
        }
        return $out;
    }

    private function evaluateMaturity(array $skill): array
    {
        $episodes = (int)$skill['episodes'];
        $successes = (int)$skill['successes'];
        $failures = (int)$skill['failures'];
        $contexts = count($skill['contexts'] ?? []);
        $rate = $episodes > 0 ? $successes / $episodes : 0.0;

        if ($episodes === 0) {
            return ['state' => self::STATE_PROPOSED, 'reason' => 'no_practice_evidence'];
        }
        if ($failures > 0 && $successes === 0) {
            return ['state' => self::STATE_EXPERIMENTING, 'reason' => 'failures_without_success'];
        }
        if ($episodes < 3 || $successes < 2) {
            return ['state' => self::STATE_EXPERIMENTING, 'reason' => 'insufficient_practice_evidence'];
        }
        if ($episodes < 5 || $successes < 4 || $contexts < 2) {
            return ['state' => self::STATE_PRACTICED, 'reason' => 'practice_established_but_not_diverse'];
        }
        if ($episodes < 8 || $successes < 7 || $contexts < 2 || $rate < 0.80) {
            return ['state' => self::STATE_RELIABLE, 'reason' => 'reliable_but_not_yet_mature'];
        }
        if ($rate >= 0.85 && $contexts >= 2) {
            return ['state' => self::STATE_AVAILABLE, 'reason' => 'evidence_threshold_met'];
        }
        return ['state' => self::STATE_RELIABLE, 'reason' => 'maturity_threshold_not_met'];
    }

    private function transitionWithEvidence(string $skillId, string $targetState, string $event, string $reason, string $source): array
    {
        $state = $this->loadState();
        $idx = $this->findSkillIndex($state, $skillId);
        if ($idx === null) {
            return ['ok' => false, 'code' => 'SKILL_NOT_FOUND'];
        }
        $now = gmdate('c');
        $state['skills'][$idx]['state'] = $targetState;
        $state['skills'][$idx]['maturity_reason'] = $this->text($reason, 400);
        $state['skills'][$idx]['updated_at'] = $now;
        $state['updated_at'] = $now;
        $this->saveState($state);
        $this->appendHistory($event, $skillId, ['reason' => $reason, 'source' => $this->text($source, 180)]);
        return ['ok' => true, 'code' => strtoupper($event), 'skill' => $state['skills'][$idx]];
    }

    private function normalizeBoundaries(array $boundaries): array
    {
        $defaults = [
            'arbitrary_code_execution' => 'FORBIDDEN',
            'direct_secret_visibility' => 'FORBIDDEN',
            'external_permission_grant' => 'FORBIDDEN',
            'protected_external_action' => 'EXTERNAL_AUTH_REQUIRED',
            'history_hard_delete' => 'FORBIDDEN',
        ];
        foreach ($boundaries as $key => $value) {
            $k = $this->token((string)$key, 80);
            $v = strtoupper($this->token((string)$value, 40));
            if ($k !== '' && in_array($v, ['AVAILABLE','DEGRADED','UNKNOWN','FORBIDDEN','STALE','EXTERNAL_AUTH_REQUIRED'], true)) {
                $defaults[$k] = $v;
            }
        }
        ksort($defaults);
        return $defaults;
    }

    private function sanitizeMetrics(array $metrics): array
    {
        $out = [];
        foreach (array_slice($metrics, 0, 24, true) as $key => $value) {
            $k = $this->token((string)$key, 64);
            if ($k === '') continue;
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $out[$k] = $value;
            } elseif (is_string($value)) {
                $out[$k] = $this->text($value, 160);
            }
        }
        return $out;
    }

    private function normalizeStrings(array $values, int $maxItems, int $maxLen): array
    {
        $out = [];
        foreach (array_slice($values, 0, $maxItems) as $value) {
            if (!is_string($value)) continue;
            $v = $this->text($value, $maxLen);
            if ($v !== '') $out[] = $v;
        }
        return array_values(array_unique($out));
    }

    private function findSkillIndex(array $state, string $skillId): ?int
    {
        foreach ($state['skills'] as $i => $skill) {
            if (($skill['id'] ?? null) === $skillId) return $i;
        }
        return null;
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new RuntimeException('SKILL_STORAGE_CREATE_FAILED');
        }
        if (!is_file($this->stateFile)) {
            $this->saveState([
                'schema' => self::SCHEMA,
                'policy' => 'archive-never-hard-delete',
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'skills' => [],
            ]);
        }
        if (!is_file($this->historyFile) && @file_put_contents($this->historyFile, '') === false) {
            throw new RuntimeException('SKILL_HISTORY_CREATE_FAILED');
        }
        @chmod($this->stateFile, 0600);
        @chmod($this->historyFile, 0600);
    }

    private function loadState(): array
    {
        $raw = @file_get_contents($this->stateFile);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || (int)($state['schema'] ?? 0) !== self::SCHEMA || !is_array($state['skills'] ?? null)) {
            throw new RuntimeException('SKILL_STATE_INVALID');
        }
        return $state;
    }

    private function saveState(array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('SKILL_STATE_ENCODE_FAILED');
        $tmp = $this->stateFile . '.tmp-' . substr(hash('sha256', uniqid('', true)), 0, 12);
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) throw new RuntimeException('SKILL_STATE_WRITE_FAILED');
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            throw new RuntimeException('SKILL_STATE_COMMIT_FAILED');
        }
        @chmod($this->stateFile, 0600);
    }

    private function appendHistory(string $event, string $skillId, array $data): void
    {
        $row = [
            'schema' => self::SCHEMA,
            'at' => gmdate('c'),
            'event' => $event,
            'skill_id' => $skillId,
            'data' => $data,
        ];
        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($this->historyFile, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('SKILL_HISTORY_APPEND_FAILED');
        }
        @chmod($this->historyFile, 0600);
    }

    private function historyCount(): int
    {
        $h = @fopen($this->historyFile, 'rb');
        if (!$h) return 0;
        $count = 0;
        while (!feof($h)) {
            if (fgets($h) !== false) $count++;
        }
        fclose($h);
        return $count;
    }

    private function token(string $value, int $maxLen): string
    {
        $value = trim($value);
        if (strlen($value) > $maxLen) $value = substr($value, 0, $maxLen);
        return preg_match('/^[A-Za-z0-9._:-]+$/', $value) ? $value : '';
    }

    private function text(string $value, int $maxLen): string
    {
        $value = trim(str_replace(["\0", "\r"], ['', ''], $value));
        if (mb_strlen($value) > $maxLen) $value = mb_substr($value, 0, $maxLen);
        return $value;
    }
}
