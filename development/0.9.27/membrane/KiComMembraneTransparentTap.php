<?php
declare(strict_types=1);
/**
 * DEVELOPMENT-ONLY contract test helper.
 *
 * The existing application action, including all its ORIGINAL authentication,
 * policy, and transport mechanics, is supplied by trusted staging code.
 * The telemetry callback receives only channel, direction, method metadata.
 *
 * Critically: no queueing, buffering, re-sending, retry, replacing original
 * errors, credentials, headers, bodies, or decisions. Only telemetry fails open.
 * This does NOT provide a security enforcement point or an OS membrane.
 */
final class KiComMembraneTransparentTap
{
    public static function run(
        string $channel,
        string $direction,
        string $method,
        callable $existingOperation,
        ?callable $telemetry = null
    ): mixed {
        if ($telemetry !== null) {
            try {
                // The observer sees only 3 explicitly chosen low-sensitivity
                // metadata values; no request / response / authorization data.
                $telemetry($channel, $direction, $method);
            } catch (Throwable $ignored) {
                // Only monitoring is optional. Original authorization and
                // execution are not caught, changed, retried or bypassed.
            }
        }
        return $existingOperation();
    }
}
