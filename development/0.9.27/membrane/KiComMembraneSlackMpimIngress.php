<?php
declare(strict_types=1);

/**
 * DEV ONLY: signed Slack message.mpim EVENT METADATA admission in isolation.
 *
 * Call with a trust-owned, explicit staging workspace/channel and a signing
 * secret supplied by the protected staging environment, NEVER from Slack body.
 * This does not install Slack, send messages, read secrets, grant authority,
 * authenticate a KiCom-generated response, or replace the native R3 routes.
 *
 * The duplicate callback MUST be backed by an atomic and independent
 * check-and-mark implementation in an eventual actual staging adapter. Merely
 * passing a mutable array/closure or an event_id cannot prove exactly-once
 * processing. We deliberately return only a candidate for further review.
 */
final class KiComMembraneSlackMpimIngress
{
    private static function state(string $code, array $evidence = []): array
    {
        return [
            'code' => $code,
            'inspection_only' => true,
            'candidate' => $code === 'SIGNED_MPIM_METADATA_CANDIDATE',
            'message_trust' => 'UNTRUSTED_EXTERNAL_INPUT',
            'action_authorized' => false,
            'delivery_authorized' => false,
            'runtime_ack_verified' => false,
            'communication_changed' => false,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<string,string> $headers normalized lowercase header names
     * @param callable(string):bool $seenEvent true iff already seen; READ-ONLY
     */
    public static function inspect(
        string $raw,
        array $headers,
        string $protectedSigningSecret,
        string $expectedWorkspace,
        string $expectedConversation,
        callable $seenEvent,
        int $now
    ): array {
        if ($protectedSigningSecret === '' || $expectedWorkspace === ''
            || $expectedConversation === '') {
            return self::state('STAGING_TRUST_CONFIGURATION_MISSING');
        }
        if ($raw === '' || strlen($raw) > 1048576) {
            return self::state('PAYLOAD_SIZE_INVALID');
        }
        $timestamp = $headers['x-slack-request-timestamp'] ?? null;
        $signature = $headers['x-slack-signature'] ?? null;
        if (!is_string($timestamp) || !preg_match('/^[0-9]{10}$/D', $timestamp)
            || !is_string($signature)
            || !preg_match('/^v0=[a-f0-9]{64}$/D', $signature)) {
            return self::state('SLACK_SIGNATURE_HEADERS_INVALID');
        }
        if (abs($now - (int)$timestamp) > 300) {
            return self::state('SLACK_TIMESTAMP_OUT_OF_WINDOW');
        }
        $computed = 'v0='.hash_hmac('sha256',
            'v0:'.$timestamp.':'.$raw, $protectedSigningSecret);
        if (!hash_equals($computed, $signature)) {
            return self::state('SLACK_SIGNATURE_INVALID');
        }
        try {
            $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return self::state('SLACK_JSON_INVALID');
        }
        if (!is_array($event) || ($event['type'] ?? null) !== 'event_callback'
            || ($event['team_id'] ?? null) !== $expectedWorkspace
            || !is_array($event['event'] ?? null)) {
            return self::state('SLACK_ENVELOPE_UNEXPECTED');
        }
        $inner = $event['event'];
        if (($inner['type'] ?? null) !== 'message'
            || ($inner['channel_type'] ?? null) !== 'mpim'
            || ($inner['channel'] ?? null) !== $expectedConversation
            || isset($inner['subtype']) || isset($inner['bot_id'])
            || !is_string($inner['user'] ?? null) || $inner['user'] === '') {
            return self::state('SLACK_MPIM_EVENT_NOT_ADMITTED');
        }
        $eventId = $event['event_id'] ?? null;
        if (!is_string($eventId)
            || !preg_match('/^[A-Za-z0-9_-]{5,180}$/D', $eventId)) {
            return self::state('SLACK_EVENT_ID_INVALID');
        }
        // The callback is a read-only hint, not an atomic admission claim.
        try {
            $alreadySeen = $seenEvent($eventId);
        } catch (Throwable $e) {
            return self::state('SLACK_DEDUPLICATION_UNAVAILABLE');
        }
        if ($alreadySeen !== false) {
            return self::state('SLACK_EVENT_ALREADY_SEEN_OR_UNKNOWN');
        }
        return self::state('SIGNED_MPIM_METADATA_CANDIDATE', [
            'workspace_sha256' => hash('sha256', $expectedWorkspace),
            'conversation_sha256' => hash('sha256', $expectedConversation),
            'event_id_sha256' => hash('sha256', $eventId),
            'sender_id_sha256' => hash('sha256', $inner['user']),
            'raw_sha256' => hash('sha256', $raw),
            'provider_signature_valid' => true,
            'deduplication_committed' => false
        ]);
    }
}
