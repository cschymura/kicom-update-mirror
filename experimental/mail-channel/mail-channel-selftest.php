<?php
declare(strict_types=1);

require_once __DIR__ . '/KiComMailChannel.php';

$configPath = dirname(__DIR__, 2) . '/candidates/mail-channel-v1/MAIL_ACCOUNT.json';
$mail = KiComMailChannel::fromJsonFile($configPath);

$profile = $mail->profile();
if (($profile['secret_visible'] ?? true) !== false) {
    fwrite(STDERR, "FAIL secret visibility\n"); exit(1);
}
if (($profile['inbound']['host'] ?? '') !== 'w021efff.kasserver.com' || ($profile['inbound']['port'] ?? 0) !== 993) {
    fwrite(STDERR, "FAIL inbound config\n"); exit(1);
}
if (($profile['outbound']['host'] ?? '') !== 'w021efff.kasserver.com' || ($profile['outbound']['port'] ?? 0) !== 465) {
    fwrite(STDERR, "FAIL outbound config\n"); exit(1);
}

$event = $mail->ingestEnvelope([
    'message_id' => '<test@example.invalid>',
    'from' => 'sender@example.invalid',
    'subject' => 'test',
    'received_at' => '2026-09-18T08:00:00+02:00',
    'body_sha256' => str_repeat('a', 64),
]);
if (($event['trust'] ?? '') !== 'UNTRUSTED' || ($event['authority_granted'] ?? true) !== false) {
    fwrite(STDERR, "FAIL inbound authority\n"); exit(1);
}

$deny = $mail->authorizeOutbound([]);
$allow = $mail->authorizeOutbound(['external_send_authorized' => true]);
if (($deny['allowed'] ?? true) !== false || ($allow['allowed'] ?? false) !== true) {
    fwrite(STDERR, "FAIL outbound authorization\n"); exit(1);
}

$secret = $mail->resolvePassword(static fn(string $ref): string => $ref === 'KICOM_MAIL_PASSWORD' ? 'test-secret' : '');
if ($secret !== 'test-secret') {
    fwrite(STDERR, "FAIL secret provider\n"); exit(1);
}

foreach (['exec','shell','grantPermission','deleteHistory'] as $forbidden) {
    if (method_exists($mail, $forbidden)) {
        fwrite(STDERR, "FAIL forbidden method {$forbidden}\n"); exit(1);
    }
}

echo "OK KiCom mail channel selftest\n";
