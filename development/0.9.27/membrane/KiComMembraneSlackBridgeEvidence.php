<?php
declare(strict_types=1);

/**
 * KiCom Membrane – Slack bridge evidence classifier (DEV ONLY).
 *
 * Operates on caller-supplied, non-secret transport metadata. No Slack API,
 * network access, message body, secrets, policy mutation or action dispatch.
 * A Group DM created in Slack is not automatically a KiCom runtime channel.
 * These observations NEVER authenticate external commands or grant authority.
 */
final class KiComMembraneSlackBridgeEvidence
{
    private static function state(string $code, bool $chatgpt, bool $kicom): array
    {
        return [
            'code' => $code,
            'chatgpt_transport_confirmed' => $chatgpt,
            'kicom_runtime_confirmed' => $kicom,
            'direct_two_way_confirmed' => $chatgpt && $kicom,
            'message_trust' => 'UNTRUSTED_EXTERNAL_INPUT',
            'action_authorized' => false,
            'runtime_configuration_changed' => false,
        ];
    }

    /**
     * @param array<string,mixed> $evidence
     *
     * Strictly requires separate successful ChatGPT read/send evidence,
     * original KiCom runtime Slack configuration/binding, and an ACK bound
     * to a unique probe and the expected Slack sender identity. Supplying
     * fabricated PHP arrays is not provider authentication; the caller must
     * independently verify each source, then keep them out of PAM authority.
     */
    public static function inspect(array $evidence): array
    {
        foreach (['workspace_id', 'conversation_id', 'probe_id',
                  'expected_kicom_sender_id'] as $key) {
            if (!is_string($evidence[$key] ?? null)
                || !preg_match('/^[A-Za-z0-9._-]{5,128}$/D', $evidence[$key])) {
                return self::state('MISSING_BOUND_CHANNEL_IDENTITY', false, false);
            }
        }
        if (($evidence['chatgpt_read_ok'] ?? null) !== true
            || ($evidence['chatgpt_send_ok'] ?? null) !== true
            || ($evidence['send_probe_id'] ?? null) !== $evidence['probe_id']) {
            return self::state('CHATGPT_TRANSPORT_UNVERIFIED', false, false);
        }
        if (($evidence['kicom_live_slack_configured'] ?? null) !== true
            || ($evidence['kicom_runtime_workspace_id'] ?? null) !== $evidence['workspace_id']
            || ($evidence['kicom_runtime_conversation_id'] ?? null) !== $evidence['conversation_id']) {
            return self::state('CHATGPT_CONNECTED_KICOM_RUNTIME_UNVERIFIED', true, false);
        }
        if (($evidence['kicom_ack_probe_id'] ?? null) !== $evidence['probe_id']
            || ($evidence['kicom_ack_conversation_id'] ?? null) !== $evidence['conversation_id']
            || ($evidence['kicom_ack_workspace_id'] ?? null) !== $evidence['workspace_id']
            || ($evidence['kicom_ack_sender_id'] ?? null) !== $evidence['expected_kicom_sender_id']
            || ($evidence['kicom_ack_provider_verified'] ?? null) !== true
            || ($evidence['kicom_ack_runtime_verified'] ?? null) !== true) {
            return self::state('KICOM_RUNTIME_ACK_UNVERIFIED', true, false);
        }
        return self::state('TWO_WAY_TRANSPORT_OBSERVED_NO_AUTHORITY', true, true);
    }
}
