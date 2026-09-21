<?php
declare(strict_types=1);

require_once __DIR__ . '/KiComEngramMcpController.php';

/**
 * DEV-only connector protocol adapter. It does not open an HTTP route and does
 * not authenticate. Verified identity is injected by the server-side caller.
 */
final class KiComEngramMcpJsonAdapter
{
    public function __construct(
        private KiComEngramMcpController $controller,
        private int $maxRequestBytes = 2048,
        private int $maxResponseBytes = 8192
    ) {
        if ($maxRequestBytes < 256 || $maxRequestBytes > 16384 || $maxResponseBytes < 512 || $maxResponseBytes > 65536) {
            throw new InvalidArgumentException('ADAPTER_LIMITS');
        }
    }

    public function handle(string $json, array $verifiedIdentity, int $now): string
    {
        if ($json === '' || strlen($json) > $this->maxRequestBytes) {
            return $this->error('REQUEST_SIZE');
        }
        try {
            $request = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($request) || array_is_list($request)) {
                return $this->error('REQUEST_JSON');
            }
            // Identity is deliberately not parsed from JSON; controller/contract
            // receive only the server-side verified identity argument.
            $response = $this->controller->dispatch($request, $verifiedIdentity, $now);
            $wire = json_encode(['ok' => true, 'result' => $response], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($wire) > $this->maxResponseBytes) {
                return $this->error('RESPONSE_SIZE');
            }
            return $wire;
        } catch (JsonException) {
            return $this->error('REQUEST_JSON');
        } catch (RuntimeException|InvalidArgumentException) {
            // Do not disclose internal auth/store/nonce/SQLite error detail to a connector.
            return $this->error('REQUEST_DENIED');
        } catch (Throwable) {
            return $this->error('INTERNAL');
        }
    }

    private function error(string $code): string
    {
        return json_encode(['ok' => false, 'error' => $code], JSON_THROW_ON_ERROR);
    }
}
