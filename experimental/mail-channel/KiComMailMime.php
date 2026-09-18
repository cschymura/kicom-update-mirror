<?php
declare(strict_types=1);

final class KiComMailMime
{
    private const MAX_DEPTH = 8;
    private const MAX_PARTS = 100;
    private const MAX_DECODED_BYTES = 31457280;
    private const MAX_TEXT_BYTES = 65536;

    private int $parts = 0;
    private int $decodedBytes = 0;

    public function parse(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 41943040) {
            throw new InvalidArgumentException('MAIL_RAW_SIZE_INVALID');
        }
        $this->parts = 0;
        $this->decodedBytes = 0;

        [$headers, $body] = $this->splitEntity($raw);
        $h = $this->parseHeaders($headers);
        $leaves = [];
        $this->walk($h, $body, 0, $leaves);

        $text = '';
        $html = '';
        $attachments = [];
        foreach ($leaves as $leaf) {
            if (($leaf['attachment'] ?? false) === true) {
                $attachments[] = $leaf;
                continue;
            }
            if (($leaf['content_type'] ?? '') === 'text/plain' && $text === '') {
                $text = (string)$leaf['text'];
            } elseif (($leaf['content_type'] ?? '') === 'text/html' && $html === '') {
                $html = (string)$leaf['text'];
            }
        }

        $messageId = trim((string)($h['message-id'] ?? ''));
        if ($messageId === '') {
            $messageId = '<sha256-' . substr(hash('sha256', $raw), 0, 32) . '@kicom.local>';
        }

        return [
            'message_id' => $messageId,
            'from' => $this->decodeHeader((string)($h['from'] ?? '')),
            'to' => $this->decodeHeader((string)($h['to'] ?? '')),
            'subject' => $this->decodeHeader((string)($h['subject'] ?? '')),
            'date' => trim((string)($h['date'] ?? '')),
            'text' => $text !== '' ? $text : $this->htmlToText($html),
            'text_sha256' => hash('sha256', $text !== '' ? $text : $html),
            'attachments' => $attachments,
            'trust' => 'UNTRUSTED',
            'authority_granted' => false,
        ];
    }

    private function walk(array $headers, string $body, int $depth, array &$leaves): void
    {
        if ($depth > self::MAX_DEPTH || ++$this->parts > self::MAX_PARTS) {
            throw new RuntimeException('MAIL_MIME_LIMIT_EXCEEDED');
        }
        [$contentType, $ctParams] = $this->parseValueParams((string)($headers['content-type'] ?? 'text/plain; charset=UTF-8'));
        [$disposition, $dispParams] = $this->parseValueParams((string)($headers['content-disposition'] ?? ''));
        $contentType = strtolower($contentType);

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = (string)($ctParams['boundary'] ?? '');
            if ($boundary === '' || strlen($boundary) > 200) {
                throw new RuntimeException('MAIL_MIME_BOUNDARY_INVALID');
            }
            $segments = explode('--' . $boundary, $body);
            foreach (array_slice($segments, 1) as $segment) {
                if (str_starts_with($segment, '--')) {
                    break;
                }
                $segment = preg_replace('/^\r?\n/', '', $segment, 1) ?? $segment;
                $segment = preg_replace('/\r?\n$/', '', $segment, 1) ?? $segment;
                if (trim($segment) === '') {
                    continue;
                }
                [$ph, $pb] = $this->splitEntity($segment);
                $this->walk($this->parseHeaders($ph), $pb, $depth + 1, $leaves);
            }
            return;
        }

        $decoded = $this->decodeTransfer($body, strtolower(trim((string)($headers['content-transfer-encoding'] ?? '8bit'))));
        $this->decodedBytes += strlen($decoded);
        if ($this->decodedBytes > self::MAX_DECODED_BYTES) {
            throw new RuntimeException('MAIL_DECODED_SIZE_LIMIT');
        }

        $filename = $this->decodeHeader((string)($dispParams['filename'] ?? $ctParams['name'] ?? ''));
        $isAttachment = strtolower($disposition) === 'attachment' || $filename !== '';
        if ($isAttachment) {
            $leaves[] = [
                'attachment' => true,
                'filename' => $filename !== '' ? $filename : 'attachment.bin',
                'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
                'declared_disposition' => strtolower($disposition),
                'size' => strlen($decoded),
                'sha256' => hash('sha256', $decoded),
                'data' => $decoded,
                'trust' => 'UNTRUSTED',
                'executable' => false,
                'auto_install' => false,
            ];
            return;
        }

        if ($contentType === 'text/plain' || $contentType === 'text/html') {
            $charset = (string)($ctParams['charset'] ?? 'UTF-8');
            $text = $this->toUtf8($decoded, $charset);
            if (strlen($text) > self::MAX_TEXT_BYTES) {
                $text = substr($text, 0, self::MAX_TEXT_BYTES);
            }
            $leaves[] = [
                'attachment' => false,
                'content_type' => $contentType,
                'text' => $text,
                'trust' => 'UNTRUSTED',
            ];
        }
    }

    private function splitEntity(string $entity): array
    {
        $pos = strpos($entity, "\r\n\r\n");
        $skip = 4;
        if ($pos === false) {
            $pos = strpos($entity, "\n\n");
            $skip = 2;
        }
        if ($pos === false) {
            return [$entity, ''];
        }
        return [substr($entity, 0, $pos), substr($entity, $pos + $skip)];
    }

    private function parseHeaders(string $raw): array
    {
        $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw) ?? $raw;
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $p = strpos($line, ':');
            if ($p === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $p)));
            if (!preg_match('/^[a-z0-9-]{1,64}$/', $name)) {
                continue;
            }
            $value = trim(substr($line, $p + 1));
            $out[$name] = isset($out[$name]) ? $out[$name] . ', ' . $value : $value;
        }
        return $out;
    }

    private function parseValueParams(string $value): array
    {
        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];
        $base = trim((string)array_shift($parts));
        $params = [];
        foreach ($parts as $part) {
            $p = strpos($part, '=');
            if ($p === false) {
                continue;
            }
            $k = strtolower(trim(substr($part, 0, $p)));
            $v = trim(substr($part, $p + 1));
            if (strlen($v) >= 2 && $v[0] === '"' && $v[strlen($v) - 1] === '"') {
                $v = stripcslashes(substr($v, 1, -1));
            }
            if (str_ends_with($k, '*') && str_contains($v, "''")) {
                [, $v] = explode("''", $v, 2);
                $v = rawurldecode($v);
                $k = rtrim($k, '*');
            }
            $params[$k] = $v;
        }
        return [$base, $params];
    }

    private function decodeTransfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => $this->strictBase64($body),
            'quoted-printable' => quoted_printable_decode($body),
            '7bit', '8bit', 'binary', '' => $body,
            default => throw new RuntimeException('MAIL_TRANSFER_ENCODING_UNSUPPORTED'),
        };
    }

    private function strictBase64(string $body): string
    {
        $clean = preg_replace('/\s+/', '', $body) ?? '';
        $decoded = base64_decode($clean, true);
        if ($decoded === false) {
            throw new RuntimeException('MAIL_BASE64_INVALID');
        }
        return $decoded;
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_decode_mimeheader')) {
            $v = @mb_decode_mimeheader($value);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        if (function_exists('iconv_mime_decode')) {
            $v = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return $value;
    }

    private function toUtf8(string $value, string $charset): string
    {
        $charset = trim($charset, " \t\r\n\"'");
        if ($charset === '' || strcasecmp($charset, 'UTF-8') === 0) {
            return $value;
        }
        if (function_exists('mb_convert_encoding')) {
            try {
                return mb_convert_encoding($value, 'UTF-8', $charset);
            } catch (Throwable) {
            }
        }
        if (function_exists('iconv')) {
            $v = @iconv($charset, 'UTF-8//IGNORE', $value);
            if (is_string($v)) {
                return $v;
            }
        }
        return $value;
    }

    private function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\r?\n\s*\r?\n+/', "\n\n", $text) ?? $text;
        return trim(substr($text, 0, self::MAX_TEXT_BYTES));
    }
}
