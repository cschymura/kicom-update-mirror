<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComPamSnapshotOrder.php';
$count = 0;
function snapOk(bool $ok, string $name): void {
    global $count;
    $count++;
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ' . $name . PHP_EOL;
}
$hash = str_repeat('a', 64);
$make = static fn(string $id, string $time, ?string $sha = null): array =>
    ['id' => $id, 'created_at' => $time, 'sha256' => $sha ?? $hash,
        'file_name' => 'snapshot-' . $id . '.sqlite'];
$old = $make('20260919101111-ffffffffff', '2026-09-19T10:11:11+00:00');
$new = $make('20260919101112-0000000000', '2026-09-19T10:11:12+00:00');
$verify = static fn(array $row): bool => $row['sha256'] === $hash;
$r = KiComPamSnapshotOrder::select([$old, $new], $verify);
snapOk($r['ok'] && $r['snapshot']['id'] === $new['id'],
    'Second-resolution timestamp orders distinct seconds, not random suffix');
$first = $make('20260919101112-0000000000', '2026-09-19T10:11:12+00:00');
$second = $make('20260919101112-ffffffffff', '2026-09-19T10:11:12+00:00');
$r = KiComPamSnapshotOrder::select([$first, $second], $verify);
snapOk(!$r['ok'] && $r['code'] === 'SNAPSHOT_ORDER_AMBIGUOUS',
    'Two snapshots in the same second reject unsupported ordering');
$r = KiComPamSnapshotOrder::select([$second, $first], $verify);
snapOk(!$r['ok'] && $r['code'] === 'SNAPSHOT_ORDER_AMBIGUOUS',
    'Reversing candidate order does not change ambiguity');
$r = KiComPamSnapshotOrder::select([$old], $verify);
snapOk($r['ok'] && $r['snapshot']['id'] === $old['id'],
    'Single verified legacy snapshot is selectable');
$r = KiComPamSnapshotOrder::select([], $verify);
snapOk(!$r['ok'] && $r['code'] === 'SNAPSHOT_COUNT_INVALID',
    'Empty selection fails closed');
$r = KiComPamSnapshotOrder::select([$old, $old], $verify);
snapOk(!$r['ok'] && $r['code'] === 'SNAPSHOT_METADATA_INVALID',
    'Duplicate snapshot IDs rejected');
$r = KiComPamSnapshotOrder::select([$new, $make('20260919101113-bbbbbbbbbb',
    '2026-09-19T10:11:13+00:00', str_repeat('b',64))], $verify);
snapOk(!$r['ok'] && $r['code'] === 'SNAPSHOT_VERIFICATION_FAILED',
    'Unverified newer backup cannot silently fall back to older');
$r = KiComPamSnapshotOrder::select([array_merge($old,
    ['created_at' => '2026-09-19T10:11:14+00:00'])], $verify);
snapOk(!$r['ok'] && $r['code'] === 'SNAPSHOT_TIMESTAMP_INVALID',
    'Timestamp inconsistent with ID is rejected');
$r = KiComPamSnapshotOrder::select([array_merge($old,
    ['created_at_us' => 1789812671000000])], $verify);
snapOk(!$r['ok'] && $r['code'] === 'UNTRUSTED_HIGH_RESOLUTION_ORDER',
    'Unverified subsecond metadata cannot override legacy ordering');
$r = KiComPamSnapshotOrder::select([$old],
    static function (array $row): bool { throw new RuntimeException('bad snapshot'); });
snapOk(!$r['ok'] && $r['code'] === 'SNAPSHOT_VERIFICATION_FAILED',
    'Verifier exception fails closed');
snapOk(KiComPamSnapshotOrder::select([$old], $verify)['snapshot']['sha256'] === $hash,
    'Selected manifest preserves exact SHA-256 identity');
echo "PAM_SNAPSHOT_ORDER_TESTS_PASSED=$count\n";
