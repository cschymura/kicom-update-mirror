<?php
declare(strict_types=1);

final class KiComMailTransport
{
    private array $config;
    private $secretProvider;

    public function __construct(array $config, callable $secretProvider)
    {
        $this->config = $config;
        $this->secretProvider = $secretProvider;
    }

    public function probe(): array
    {
        $imap = $this->probeImap();
        $smtp = $this->probeSmtp();
        return [
            'imap' => $imap,
            'smtp' => $smtp,
            'ready' => ($imap['ok'] ?? false) && ($smtp['ok'] ?? false),
            'credential_visible' => false,
            'authority_changed' => false,
        ];
    }

    public function probeImap(): array
    {
        $in = $this->config['inbound'] ?? [];
        $socket = $this->openTlsSocket((string)$in['host'], (int)$in['port']);
        try {
            $greeting = $this->readImapUntilGreeting($socket);
            if (!preg_match('/^\*\s+(OK|PREAUTH)\b/i', $greeting)) {
                throw new RuntimeException('IMAP_GREETING_INVALID');
            }
            $password = $this->secret();
            $tag = 'K001';
            $cmd = $tag . ' LOGIN ' . $this->imapQuote((string)$in['username']) . ' ' . $this->imapQuote($password) . "\r\n";
            $this->writeAll($socket, $cmd);
            $reply = $this->readUntilTagged($socket, $tag);
            if (!preg_match('/^' . preg_quote($tag, '/') . '\s+OK\b/im', $reply)) {
                throw new RuntimeException('IMAP_AUTH_FAILED');
            }
            $this->writeAll($socket, "K002 LOGOUT\r\n");
            return ['ok' => true, 'tls' => true, 'authenticated' => true];
        } finally {
            fclose($socket);
        }
    }

    public function probeSmtp(): array
    {
        $out = $this->config['outbound'] ?? [];
        $socket = $this->openTlsSocket((string)$out['host'], (int)$out['port']);
        try {
            $this->expectSmtp($socket, [220]);
            $hostName = 'kicom.rurtalbahn.info';
            $this->writeAll($socket, "EHLO {$hostName}\r\n");
            $ehlo = $this->readSmtpResponse($socket);
            if (($ehlo['code'] ?? 0) !== 250) {
                throw new RuntimeException('SMTP_EHLO_FAILED');
            }
            $password = $this->secret();
            $this->writeAll($socket, "AUTH LOGIN\r\n");
            $this->expectSmtp($socket, [334]);
            $this->writeAll($socket, base64_encode((string)$out['username']) . "\r\n");
            $this->expectSmtp($socket, [334]);
            $this->writeAll($socket, base64_encode($password) . "\r\n");
            $this->expectSmtp($socket, [235]);
            $this->writeAll($socket, "QUIT\r\n");
            return ['ok' => true, 'tls' => true, 'authenticated' => true];
        } finally {
            fclose($socket);
        }
    }

    public function sendText(string $to, string $subject, string $body, array $headers = []): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('MAIL_TO_INVALID');
        }

        $identity = (string)($this->config['identity']['address'] ?? '');
        $out = $this->config['outbound'] ?? [];
        $messageId = '<kicom-' . bin2hex(random_bytes(16)) . '@rurtalbahn.info>';
        $date = gmdate('D, d M Y H:i:s O');

        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        $safeBody = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = [
            'Date: ' . $date,
            'From: KiCom <' . $identity . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($safeSubject),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
            'X-KiCom-Origin: bounded-mail-channel-v1',
        ];

        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', (string)$name)) {
                throw new InvalidArgumentException('MAIL_HEADER_NAME_INVALID');
            }
            $v = str_replace(["\r", "\n"], '', (string)$value);
            $lines[] = $name . ': ' . $v;
        }

        $data = implode("\r\n", $lines) . "\r\n\r\n" .
            str_replace("\n", "\r\n", $safeBody) . "\r\n";
        $data = preg_replace('/(?m)^\./', '..', $data) ?? $data;

        $socket = $this->openTlsSocket((string)$out['host'], (int)$out['port']);
        try {
            $this->expectSmtp($socket, [220]);
            $this->writeAll($socket, "EHLO kicom.rurtalbahn.info\r\n");
            $this->expectSmtp($socket, [250]);
            $password = $this->secret();
            $this->writeAll($socket, "AUTH LOGIN\r\n");
            $this->expectSmtp($socket, [334]);
            $this->writeAll($socket, base64_encode((string)$out['username']) . "\r\n");
            $this->expectSmtp($socket, [334]);
            $this->writeAll($socket, base64_encode($password) . "\r\n");
            $this->expectSmtp($socket, [235]);

            $this->writeAll($socket, 'MAIL FROM:<' . $identity . ">\r\n");
            $this->expectSmtp($socket, [250]);
            $this->writeAll($socket, 'RCPT TO:<' . $to . ">\r\n");
            $this->expectSmtp($socket, [250, 251]);
            $this->writeAll($socket, "DATA\r\n");
            $this->expectSmtp($socket, [354]);
            $this->writeAll($socket, $data . ".\r\n");
            $accepted = $this->expectSmtp($socket, [250]);
            $this->writeAll($socket, "QUIT\r\n");

            return [
                'accepted' => true,
                'message_id' => $messageId,
                'smtp_code' => $accepted['code'],
                'credential_visible' => false,
            ];
        } finally {
            fclose($socket);
        }
    }

    public function inboxHasMessageId(string $messageId): bool
    {
        if (!preg_match('/^<[^\r\n<>]+@[^\r\n<>]+>$/', $messageId)) {
            throw new InvalidArgumentException('MAIL_MESSAGE_ID_INVALID');
        }
        $in = $this->config['inbound'] ?? [];
        $socket = $this->openTlsSocket((string)$in['host'], (int)$in['port']);
        try {
            $this->readImapUntilGreeting($socket);
            $password = $this->secret();
            $this->writeAll($socket, 'K101 LOGIN ' . $this->imapQuote((string)$in['username']) . ' ' . $this->imapQuote($password) . "\r\n");
            if (!preg_match('/^K101\s+OK\b/im', $this->readUntilTagged($socket, 'K101'))) {
                throw new RuntimeException('IMAP_AUTH_FAILED');
            }
            $this->writeAll($socket, "K102 SELECT INBOX\r\n");
            if (!preg_match('/^K102\s+OK\b/im', $this->readUntilTagged($socket, 'K102'))) {
                throw new RuntimeException('IMAP_SELECT_FAILED');
            }
            $this->writeAll($socket, 'K103 SEARCH HEADER Message-ID ' . $this->imapQuote($messageId) . "\r\n");
            $reply = $this->readUntilTagged($socket, 'K103');
            $this->writeAll($socket, "K104 LOGOUT\r\n");
            if (!preg_match('/^K103\s+OK\b/im', $reply)) {
                throw new RuntimeException('IMAP_SEARCH_FAILED');
            }
            return (bool)preg_match('/^\* SEARCH\s+\d+/mi', $reply);
        } finally {
            fclose($socket);
        }
    }

    private function secret(): string
    {
        $value = ($this->secretProvider)();
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('MAIL_SECRET_UNAVAILABLE');
        }
        return $value;
    }

    private function openTlsSocket(string $host, int $port)
    {
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('MAIL_ENDPOINT_INVALID');
        }
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'disable_compression' => true,
            ],
        ]);
        $errno = 0; $errstr = '';
        $socket = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('MAIL_TLS_CONNECT_FAILED:' . $errno);
        }
        stream_set_timeout($socket, 15);
        return $socket;
    }

    private function writeAll($socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $n = fwrite($socket, substr($data, $offset));
            if ($n === false || $n === 0) {
                throw new RuntimeException('MAIL_SOCKET_WRITE_FAILED');
            }
            $offset += $n;
        }
    }

    private function readImapUntilGreeting($socket): string
    {
        $line = fgets($socket, 8192);
        if (!is_string($line)) {
            throw new RuntimeException('IMAP_GREETING_MISSING');
        }
        return $line;
    }

    private function readUntilTagged($socket, string $tag): string
    {
        $buf = '';
        while (!feof($socket) && strlen($buf) < 1048576) {
            $line = fgets($socket, 65536);
            if (!is_string($line)) {
                break;
            }
            $buf .= $line;
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line)) {
                return $buf;
            }
        }
        throw new RuntimeException('IMAP_RESPONSE_INCOMPLETE');
    }

    private function imapQuote(string $value): string
    {
        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new InvalidArgumentException('IMAP_VALUE_INVALID');
        }
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    private function expectSmtp($socket, array $codes): array
    {
        $r = $this->readSmtpResponse($socket);
        if (!in_array($r['code'], $codes, true)) {
            throw new RuntimeException('SMTP_UNEXPECTED_RESPONSE:' . $r['code']);
        }
        return $r;
    }

    private function readSmtpResponse($socket): array
    {
        $lines = [];
        $code = 0;
        while (!feof($socket) && count($lines) < 100) {
            $line = fgets($socket, 8192);
            if (!is_string($line)) {
                break;
            }
            $lines[] = rtrim($line, "\r\n");
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                $code = (int)$m[1];
                if ($m[2] === ' ') {
                    return ['code' => $code, 'lines' => $lines];
                }
            }
        }
        throw new RuntimeException('SMTP_RESPONSE_INCOMPLETE');
    }

    private function encodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
