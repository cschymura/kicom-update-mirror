<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramStore.php';

/**
 * ISOLATED DEV ONLY. No HTTP endpoint, no auto-ingestion, no credentials,
 * no access to source chats. This gate does not authenticate callers.
 *
 * The two callbacks MUST be supplied by a future reviewed, identity-bound,
 * server-side KiCom adapter, NEVER from untrusted HTTP payload or text:
 *  identity(): verified subject + allowed namespaces;
 *  consumeApproval(binding): true only when an independently authenticated,
 *      exact-record human approval is atomically consumed, once.
 *
 * Refuse rather than silently redact suspected secrets. This deliberately
 * incomplete detector is not a substitute for human sensitive-data review.
 * No private text or source reference may enter public GitHub/Slack/Mail logs.
 */
final class KiComEngramIngestionGate
{
    private KiComEngramStore $store;
    private $trustedIdentity;
    private $consumeApproval;

    public function __construct(KiComEngramStore $store, callable $trustedIdentity, callable $consumeApproval)
    {
        $this->store = $store;
        $this->trustedIdentity = $trustedIdentity;
        $this->consumeApproval = $consumeApproval;
    }

    private static function validKey(string $value): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $value) === 1;
    }

    private static function forbiddenContent(string $body): bool
    {
        // Conservative recognizable credentials, tokens and transport secrets.
        // Never print the matching substring.
        foreach ([
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----/i',
            '/\b(?:bearer|authorization|cookie|set-cookie|password|passwort|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token|session[_-]?token|otp|totp|recovery[_-]?code)\b\s*(?::|=|is\b|\s)\s*[^\s,;]{3,}/iu',
            '/\b(?:sk-[A-Za-z0-9_-]{16,}|gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/',
            '/\bhttps?:\/\/[^\s]+(?:\?[^\s]*|[A-Za-z0-9]:[^@\/\s]+@)[^\s]*/iu',
            '/\b(?:\d[ -]?){13,19}\b/',
        ] as $pattern) {
            if (preg_match($pattern, $body) === 1) return true;
        }
        return false;
    }

    /** Strict per-record opt-in. Returns only an id/hash, never stored text. */
    public function ingest(array $entry): array
    {
        // Reject unknown fields rather than ever silently accepting bulk,
        // sender-supplied subject, raw chat, or an invented approval flag.
        $allowed = ['namespace','kind','body','source_kind','source_ref','sensitivity'];
        $keys = array_keys($entry);
        sort($keys);
        $expected = $allowed;
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('ENGRAM_INGEST_FIELDS_FORBIDDEN');
        }
        foreach ($allowed as $name) {
            if (!is_string($entry[$name])) {
                throw new RuntimeException('ENGRAM_INGEST_FIELDS_INVALID');
            }
        }
        $namespace = $entry['namespace'];
        $kind = $entry['kind'];
        $body = $entry['body'];
        $sourceKind = $entry['source_kind'];
        $sourceRef = $entry['source_ref'];
        if (!self::validKey($namespace)
            || !in_array($kind, ['collaboration','decision','lesson','technical'], true)
            || !in_array($sourceKind, ['explicit_user','approved_summary'], true)
            || !preg_match('/\A(?:note|summary):[a-z0-9._:-]{1,80}\z/D', $sourceRef)
            || $entry['sensitivity'] !== 'ordinary'
            || strlen($body) < 1 || strlen($body) > 4096 || !preg_match('//u', $body)
            || self::forbiddenContent($body)) {
            throw new RuntimeException('ENGRAM_INGEST_CONTENT_REJECTED');
        }

        $identity = ($this->trustedIdentity)();
        if (!is_array($identity)
            || ($identity['verified'] ?? null) !== true
            || !is_string($identity['subject'] ?? null)
            || !self::validKey($identity['subject'])
            || !is_array($identity['namespaces'] ?? null)
            || !in_array($namespace, $identity['namespaces'], true)) {
            throw new RuntimeException('ENGRAM_INGEST_IDENTITY_FORBIDDEN');
        }
        // Exact-bound consent must be consumed atomically by the trusted server
        // callback (not merely claimed in the entry or checked in this class).
        $binding = [
            'subject' => $identity['subject'],
            'namespace' => $namespace,
            'kind' => $kind,
            'body_sha256' => hash('sha256', $body),
            'source_kind' => $sourceKind,
            'source_ref_sha256' => hash('sha256', $sourceRef),
            'sensitivity' => 'ordinary',
        ];
        if (($this->consumeApproval)($binding) !== true) {
            throw new RuntimeException('ENGRAM_INGEST_APPROVAL_REQUIRED');
        }
        return $this->store->create(
            $identity['subject'], $namespace, $kind, $body, $sourceKind, $sourceRef
        );
    }
}
