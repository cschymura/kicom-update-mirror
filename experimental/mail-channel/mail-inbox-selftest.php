<?php
declare(strict_types=1);

require_once __DIR__ . '/KiComMailMime.php';
require_once __DIR__ . '/KiComMailQuarantine.php';
require_once __DIR__ . '/KiComMailInbox.php';

$tmp = sys_get_temp_dir() . '/kicom-mail-inbox-' . bin2hex(random_bytes(6));
$quarantineDir = $tmp . '/quarantine';
$stateDir = $tmp . '/state';

$zipPayload = "PK\x03\x04" . "KICOM-TEST-NOT-EXECUTABLE";
$boundary = 'kicom-boundary-test';
$raw =
    "From: Christoph <sender@example.invalid>\r\n" .
    "To: KiCom <kicom@rurtalbahn.info>\r\n" .
    "Subject: KiCom Attachment Test\r\n" .
    "Message-ID: <kicom-inbox-test@example.invalid>\r\n" .
    "Date: Fri, 18 Sep 2026 10:50:00 +0200\r\n" .
    "MIME-Version: 1.0\r\n" .
    "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n" .
    "--{$boundary}\r\n" .
    "Content-Type: text/plain; charset=UTF-8\r\n" .
    "Content-Transfer-Encoding: 8bit\r\n\r\n" .
    "Hallo KiCom. Dies ist untrusted perception input.\r\n" .
    "--{$boundary}\r\n" .
    "Content-Type: application/zip; name=\"update.zip\"\r\n" .
    "Content-Disposition: attachment; filename=\"update.zip\"\r\n" .
    "Content-Transfer-Encoding: base64\r\n\r\n" .
    chunk_split(base64_encode($zipPayload), 76, "\r\n") .
    "--{$boundary}--\r\n";

$mime = new KiComMailMime();
$quarantine = new KiComMailQuarantine($quarantineDir);
$fetcher = static fn(int $limit): array => [['uid' => '42', 'raw' => $raw]];
$inbox = new KiComMailInbox($fetcher, $mime, $quarantine, $stateDir);

$first = $inbox->poll(10);
if (($first['new_messages'] ?? -1) !== 1) {
    fwrite(STDERR, "FAIL first poll count\n"); exit(1);
}
$event = $first['events'][0] ?? [];
if (($event['trust'] ?? '') !== 'UNTRUSTED' || ($event['authority_granted'] ?? true) !== false) {
    fwrite(STDERR, "FAIL authority boundary\n"); exit(1);
}
if (!str_contains((string)($event['body_preview'] ?? ''), 'Hallo KiCom')) {
    fwrite(STDERR, "FAIL body perception\n"); exit(1);
}
$att = $event['attachments'][0] ?? [];
if (($att['zip_like'] ?? false) !== true
    || ($att['auto_install'] ?? true) !== false
    || ($att['auto_extract'] ?? true) !== false
    || ($att['executable'] ?? true) !== false
    || ($att['verifier_required_for_release'] ?? false) !== true) {
    fwrite(STDERR, "FAIL quarantine policy\n"); exit(1);
}

$blobs = glob($quarantineDir . '/*/*.bin') ?: [];
if (count($blobs) !== 1 || str_ends_with(strtolower($blobs[0]), '.zip')) {
    fwrite(STDERR, "FAIL quarantine storage shape\n"); exit(1);
}
if (!is_file($quarantineDir . '/.htaccess')) {
    fwrite(STDERR, "FAIL quarantine deny file\n"); exit(1);
}

$second = $inbox->poll(10);
if (($second['new_messages'] ?? -1) !== 0) {
    fwrite(STDERR, "FAIL dedup\n"); exit(1);
}
$status = $inbox->status();
if (($status['attachments_auto_execute'] ?? true) !== false
    || ($status['attachments_auto_install'] ?? true) !== false
    || ($status['attachments'] ?? '') !== 'QUARANTINE_ONLY') {
    fwrite(STDERR, "FAIL inbox status policy\n"); exit(1);
}

$rm = static function (string $path) use (&$rm): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $rm($path . '/' . $name);
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};
$rm($tmp);

echo "OK KiCom mail inbox/quarantine selftest\n";
