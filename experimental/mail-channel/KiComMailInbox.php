<?php
declare(strict_types=1);

final class KiComMailInbox
{
    private $fetcher;
    private KiComMailMime $mime;
    private KiComMailQuarantine $quarantine;
    private string $stateDir;

    public function __construct(callable $fetcher, KiComMailMime $mime, KiComMailQuarantine $quarantine, string $stateDir)
    {
        $this->fetcher = $fetcher;
        $this->mime = $mime;
        $this->quarantine = $quarantine;
        $this->stateDir = rtrim($stateDir, '/\\');
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0700, true) && !is_dir($this->stateDir)) {
            throw new RuntimeException('MAIL_STATE_DIR_FAILED');
        }
        @chmod($this->stateDir, 0700);
    }

    public function poll(int $limit = 10): array
    {
        $limit = max(1, min(25, $limit));
        $items = ($this->fetcher)($limit);
        if (!is_array($items)) {
            throw new RuntimeException('MAIL_FETCH_RESULT_INVALID');
        }

        $state = $this->loadState();
        $events = [];
        foreach ($items as $item) {
            $uid = (string)($item['uid'] ?? '');
            $raw = $item['raw'] ?? null;
            if ($uid === '' || !is_string($raw)) {
                continue;
            }
            $parsed = $this->mime->parse($raw);
            $dedup = hash('sha256', $uid . "\0" . (string)$parsed['message_id']);
            if (isset($state['seen'][$dedup])) {
                continue;
            }

            $attachmentMeta = [];
            foreach ($parsed['attachments'] as $attachment) {
                $attachmentMeta[] = $this->quarantine->store($attachment, (string)$parsed['message_id']);
            }

            $event = [
                'event_type' => 'mail_received',
                'trust' => 'UNTRUSTED',
                'authority_granted' => false,
                'uid' => $uid,
                'message_id' => (string)$parsed['message_id'],
                'from' => mb_substr((string)$parsed['from'], 0, 1024),
                'to' => mb_substr((string)$parsed['to'], 0, 1024),
                'subject' => mb_substr((string)$parsed['subject'], 0, 998),
                'date' => mb_substr((string)$parsed['date'], 0, 128),
                'body_sha256' => (string)$parsed['text_sha256'],
                'body_preview' => mb_substr((string)$parsed['text'], 0, 16384),
                'attachments' => $attachmentMeta,
                'links' => 'UNTRUSTED',
                'received_by_kicom_at' => gmdate('c'),
            ];
            $this->appendEvent($event);
            $events[] = $event;
            $state['seen'][$dedup] = time();
        }

        if (count($state['seen']) > 4096) {
            asort($state['seen'], SORT_NUMERIC);
            $state['seen'] = array_slice($state['seen'], -2048, null, true);
        }
        $state['last_poll_at'] = gmdate('c');
        $this->saveState($state);

        return [
            'ok' => true,
            'new_messages' => count($events),
            'events' => $events,
            'trust' => 'UNTRUSTED',
            'authority_changed' => false,
            'attachments_auto_execute' => false,
        ];
    }

    public function status(): array
    {
        $state = $this->loadState();
        return [
            'configured' => true,
            'last_poll_at' => $state['last_poll_at'] ?? null,
            'dedup_entries' => count($state['seen'] ?? []),
            'inbound_authority' => 'UNTRUSTED_PERCEPTION_INPUT',
            'attachments' => 'QUARANTINE_ONLY',
            'attachments_auto_execute' => false,
            'attachments_auto_install' => false,
        ];
    }

    private function loadState(): array
    {
        $path = $this->stateDir . '/inbox-state.json';
        if (!is_file($path)) {
            return ['seen' => [], 'last_poll_at' => null];
        }
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !is_array($decoded['seen'] ?? null)) {
            return ['seen' => [], 'last_poll_at' => null];
        }
        return $decoded;
    }

    private function saveState(array $state): void
    {
        $path = $this->stateDir . '/inbox-state.json';
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('MAIL_STATE_WRITE_FAILED');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('MAIL_STATE_COMMIT_FAILED');
        }
        @chmod($path, 0600);
    }

    private function appendEvent(array $event): void
    {
        $path = $this->stateDir . '/events-' . gmdate('Ym') . '.jsonl';
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('MAIL_EVENT_APPEND_FAILED');
        }
        @chmod($path, 0600);
    }
}
