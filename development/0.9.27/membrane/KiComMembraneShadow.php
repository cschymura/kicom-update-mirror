<?php
declare(strict_types=1);

/**
 * KiCom Membrane — development-only, passive observation layer.
 *
 * No access to HTTP globals, payload bytes, message content, URLs, headers,
 * credentials, sessions, files, DB, network, or transport callbacks.
 * Cannot authorize, deny, forward, replay, queue, or change a message.
 *
 * An independently enforced host boundary would be required before calling
 * this a real OS/filesystem membrane. Observation failures may not block
 * existing communication; protected operations still use their ORIGINAL
 * authorization and verifier, never this observer.
 */
final class KiComMembraneShadow
{
    private const CHANNELS = [
        'kcl_https' => ['GET','POST'],
        'dev_api' => ['GET','POST'],
        'slack_events' => ['GET','POST'],
        'slack_outbound' => ['GET','POST'],
        'mail_inbox' => ['GET','POST'],
        'mail_outbound' => ['GET','POST'],
        'update_pull' => ['GET','POST'],
        'update_push' => ['POST'],
        'browser_admin' => ['GET','POST'],
        'browser_opera' => ['GET','POST'],
        'github_mirror' => ['GET','POST'],
    ];

    /** This only classifies metadata. It never receives or accesses a payload. */
    public static function observe(string $channel, string $direction, string $method): array
    {
        if (!isset(self::CHANNELS[$channel])
            || !in_array($direction, ['in','out'], true)
            || !in_array($method, self::CHANNELS[$channel], true)) {
            return [
                'code' => 'UNKNOWN_BOUNDARY',
                'classification' => 'untrusted',
                'relevant_authority' => 'original_runtime',
                'message_accessed' => false,
                'communication_changed' => false,
                'action_authorized' => false,
            ];
        }
        return [
            'code' => 'BOUNDARY_OBSERVED',
            'channel' => $channel,
            'direction' => $direction,
            'method' => $method,
            'classification' => 'external_transport',
            'relevant_authority' => 'original_runtime',
            'message_accessed' => false,
            'communication_changed' => false,
            'action_authorized' => false,
        ];
    }

    /**
     * Safe metadata-only monitoring: even a failed observer cannot interrupt
     * the legacy communications data plane. Never wrap the actual action
     * execution or suppress its own permission checks.
     */
    public static function tryObserve(string $channel, string $direction, string $method): array
    {
        try {
            return self::observe($channel, $direction, $method);
        } catch (Throwable $e) {
            return [
                'code' => 'SHADOW_UNAVAILABLE',
                'classification' => 'unknown',
                'relevant_authority' => 'original_runtime',
                'message_accessed' => false,
                'communication_changed' => false,
                'action_authorized' => false,
            ];
        }
    }
}
