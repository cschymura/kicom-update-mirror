<?php
declare(strict_types=1);

final class KiComMailChannel
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $this->validateConfig($config);
    }

    public static function fromJsonFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('MAIL_CONFIG_UNREADABLE');
        }
        $cfg = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($cfg)) {
            throw new RuntimeException('MAIL_CONFIG_INVALID');
        }
        return new self($cfg);
    }

    public function profile(): array
    {
        return [
            'identity' => $this->config['identity'],
            'inbound' => $this->publicEndpoint($this->config['inbound']),
            'outbound' => $this->publicEndpoint($this->config['outbound']),
            'credential_ref' => (string)$this->config['credentials']['password_source'],
            'secret_visible' => false,
            'inbound_authority' => 'UNTRUSTED_PERCEPTION_INPUT',
            'outbound_authority' => 'EXTERNAL_ACTION',
        ];
    }

    public function capabilities(): array
    {
        return [
            'mail.receive' => 'CONFIGURED',
            'mail.send' => 'CONFIGURED_EXTERNAL_AUTH_REQUIRED',
            'mail.attachments' => 'UNTRUSTED',
            'mail.links' => 'UNTRUSTED',
            'mail.grant_authority' => 'FORBIDDEN',
            'mail.secret_visibility' => 'FORBIDDEN',
        ];
    }

    public function ingestEnvelope(array $message): array
    {
        $messageId = trim((string)($message['message_id'] ?? ''));
        $from = trim((string)($message['from'] ?? ''));
        $subject = trim((string)($message['subject'] ?? ''));
        $receivedAt = trim((string)($message['received_at'] ?? ''));
        $bodyHash = trim((string)($message['body_sha256'] ?? ''));

        if ($messageId === '' || $from === '' || $receivedAt === '') {
            throw new InvalidArgumentException('MAIL_ENVELOPE_INCOMPLETE');
        }
        if ($bodyHash !== '' && !preg_match('/^[a-f0-9]{64}$/', $bodyHash)) {
            throw new InvalidArgumentException('MAIL_BODY_HASH_INVALID');
        }

        return [
            'event_type' => 'mail_received',
            'trust' => 'UNTRUSTED',
            'authority_granted' => false,
            'message_id' => $messageId,
            'from' => $from,
            'subject' => mb_substr($subject, 0, 998),
            'received_at' => $receivedAt,
            'body_sha256' => $bodyHash !== '' ? $bodyHash : null,
            'attachments' => 'UNTRUSTED',
            'links' => 'UNTRUSTED',
        ];
    }

    public function authorizeOutbound(array $actionContext): array
    {
        $allowed = ($actionContext['external_send_authorized'] ?? false) === true;
        return [
            'allowed' => $allowed,
            'reason' => $allowed ? 'AUTHORIZED_ACTION_CONTEXT' : 'EXTERNAL_AUTH_REQUIRED',
            'authority_changed' => false,
            'credential_visible' => false,
        ];
    }

    public function resolvePassword(callable $secretProvider): string
    {
        $ref = (string)$this->config['credentials']['password_source'];
        $secret = $secretProvider($ref);
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('MAIL_SECRET_UNAVAILABLE');
        }
        return $secret;
    }

    private function validateConfig(array $cfg): array
    {
        foreach (['identity','inbound','outbound','credentials'] as $key) {
            if (!isset($cfg[$key]) || !is_array($cfg[$key])) {
                throw new InvalidArgumentException('MAIL_CONFIG_MISSING_' . strtoupper($key));
            }
        }
        $address = (string)($cfg['identity']['address'] ?? '');
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('MAIL_IDENTITY_INVALID');
        }

        foreach (['inbound','outbound'] as $name) {
            $ep = $cfg[$name];
            $host = (string)($ep['host'] ?? '');
            $port = (int)($ep['port'] ?? 0);
            $username = (string)($ep['username'] ?? '');
            if ($host === '' || $port < 1 || $port > 65535 || $username === '') {
                throw new InvalidArgumentException('MAIL_ENDPOINT_INVALID_' . strtoupper($name));
            }
            if (!in_array((string)($ep['encryption'] ?? ''), ['SSL/TLS'], true)) {
                throw new InvalidArgumentException('MAIL_TLS_REQUIRED');
            }
            if ((string)($ep['auth'] ?? '') !== 'password') {
                throw new InvalidArgumentException('MAIL_AUTH_UNSUPPORTED');
            }
        }

        $ref = (string)($cfg['credentials']['password_source'] ?? '');
        if ($ref === '' || str_contains(strtolower($ref), 'password=')) {
            throw new InvalidArgumentException('MAIL_SECRET_REF_INVALID');
        }
        return $cfg;
    }

    private function publicEndpoint(array $ep): array
    {
        return [
            'protocol' => (string)$ep['protocol'],
            'host' => (string)$ep['host'],
            'port' => (int)$ep['port'],
            'encryption' => (string)$ep['encryption'],
            'auth' => (string)$ep['auth'],
            'username' => (string)$ep['username'],
            'account_id' => (string)($ep['account_id'] ?? ''),
        ];
    }
}
