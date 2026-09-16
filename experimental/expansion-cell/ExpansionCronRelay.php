<?php
declare(strict_types=1);

require_once __DIR__.'/ExpansionProtocol.php';

/**
 * Signed parent/child cron federation helper.
 *
 * It creates and verifies protocol envelopes only. HTTP transport remains a
 * separate concern so credentials, redirects and host policy cannot leak into
 * the signing layer.
 */
final class KiComExpansionCronRelay
{
    /** @param array<string,mixed> $parent @param array<string,mixed> $child @param array<string,mixed> $payload */
    public static function createTick(array $parent, string $parentSecretKey, array $child, array $payload = []): array
    {
        self::requireDescriptor($parent,'PARENT');
        self::requireDescriptor($child,'CHILD');
        if (($child['state'] ?? '') !== 'active') throw new InvalidArgumentException('CHILD_NOT_ACTIVE');
        $payload += [
            'tick_id'=>KiComExpansionProtocol::randomId('tick-'),
            'sent_at'=>gmdate('c'),
        ];
        return KiComExpansionProtocol::signEnvelope(
            (string)$parent['cell_id'],
            (string)$child['cell_id'],
            'FEDERATION_TICK',
            $payload,
            $parentSecretKey
        );
    }

    /** @param array<string,mixed> $envelope @param array<string,mixed> $parent @param array<string,mixed> $child */
    public static function verifyTickResult(array $envelope, array $parent, array $child): array
    {
        self::requireDescriptor($parent,'PARENT');
        self::requireDescriptor($child,'CHILD');
        $check=KiComExpansionProtocol::verifyEnvelope(
            $envelope,
            (string)$child['cell_id'],
            (string)$parent['cell_id'],
            (string)$child['public_key']
        );
        if (empty($check['ok'])) return $check;
        if (strtoupper((string)($envelope['operation'] ?? '')) !== 'FEDERATION_TICK_RESULT') {
            return ['ok'=>false,'code'=>'FEDERATION_TICK_RESULT_OPERATION_INVALID'];
        }
        return [
            'ok'=>true,
            'code'=>'FEDERATION_TICK_RESULT_OK',
            'message_id'=>(string)($envelope['message_id'] ?? ''),
            'payload'=>$envelope['payload'] ?? [],
        ];
    }

    /** @param array<string,mixed> $descriptor */
    private static function requireDescriptor(array $descriptor,string $prefix): void
    {
        foreach (['cell_id','public_key','base_url'] as $key) {
            if (!isset($descriptor[$key]) || !is_string($descriptor[$key]) || trim($descriptor[$key])==='') {
                throw new InvalidArgumentException($prefix.'_DESCRIPTOR_INVALID');
            }
        }
    }
}
