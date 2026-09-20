<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramPrivatePathProbe.php';

/**
 * Internal DEV-only operation handler, NOT an HTTP endpoint or authenticator.
 * Must be registered only with the fixed KiCom DEV router operation
 * DEV_ENGRAM_PATH_PROBE -> engram.path.probe after its real session check.
 * The trusted server configuration is a closure created by reviewed server
 * bootstrap code; never supply filesystem paths from HTTP/query/payload.
 */
final class KiComEngramDevPathHandler
{
    public static function handlers(callable $trustedServerConfig): array
    {
        return [
            'DEV_ENGRAM_PATH_PROBE' => static function (array $payload, array $auth) use ($trustedServerConfig): array {
                if ($payload !== []) {
                    return ['ok' => false, 'code' => 'ENGRAM_PATH_PROBE_PAYLOAD_FORBIDDEN'];
                }
                if (($auth['scope'] ?? '') !== 'dev'
                    || empty($auth['ok'])
                    || !in_array('engram.path.probe', $auth['capabilities'] ?? [], true)) {
                    return ['ok' => false, 'code' => 'ENGRAM_PATH_PROBE_AUTH_FORBIDDEN'];
                }
                try {
                    $config = $trustedServerConfig();
                    if (!is_array($config) || array_keys($config) !== ['data', 'backups', 'webroots']
                        || !is_string($config['data']) || !is_string($config['backups'])
                        || !is_array($config['webroots'])) {
                        throw new RuntimeException('Trusted probe config missing');
                    }
                    $result = KiComEngramPrivatePathProbe::runAgainstWebRoots(
                        $config['data'], $config['backups'], $config['webroots']
                    );
                } catch (Throwable $error) {
                    // Never expose absolute paths, PHP identity, exceptions or file contents.
                    return ['ok' => false, 'code' => 'ENGRAM_PATH_PROBE_UNAVAILABLE'];
                }
                return ['ok' => true, 'code' => 'DEV_ENGRAM_PATH_PROBE_OK'] + $result;
            },
        ];
    }
}
