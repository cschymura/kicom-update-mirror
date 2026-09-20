<?php
declare(strict_types=1);
require_once __DIR__ . '/KiComEngramAuthenticatedBridge.php';

$bridgeChecks = 0;
$pass = static function (bool $ok, string $what) use (&$bridgeChecks): void {
    if (!$ok) { throw new RuntimeException('FAIL engram bridge: ' . $what); }
    $bridgeChecks++;
    echo "PASS bridge " . $what . "\n";
};
$deny = static function (callable $fn, string $what) use ($pass): void {
    $rejected = false;
    try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { $rejected = true; }
    $pass($rejected, $what);
};
$cleanup = static function (string $path) use (&$cleanup): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') { $cleanup($path . '/' . $name); }
    }
    rmdir($path);
};

$root = sys_get_temp_dir() . '/engram-bridge-synthetic-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/web', 0755);
mkdir($root . '/private', 0700);
try {
    $store = new KiComEngramStore($root . '/private', $root . '/web');
    $actorA = [
        'verified' => true,
        'subject' => 'synthetic-a',
        'namespaces' => ['project'],
        'engram_rights' => ['engram.read','engram.write']
    ];
    $actorB = [
        'verified' => true,
        'subject' => 'synthetic-b',
        'namespaces' => ['project'],
        'engram_rights' => ['engram.read','engram.write']
    ];
    $entry = [
        'namespace' => 'project', 'kind' => 'technical',
        'body' => 'Synthetic violet locomotive engram for independent retrieval.',
        'source_kind' => 'approved_summary',
        'source_ref' => 'summary:synthetic-roundtrip-01', 'sensitivity' => 'ordinary'
    ];
    $approvalCount = 0;
    $oneTimeApproval = static function (array $binding) use (&$approvalCount, $entry): bool {
        if ($approvalCount !== 0
            || ($binding['subject'] ?? '') !== 'synthetic-a'
            || ($binding['namespace'] ?? '') !== 'project'
            || ($binding['body_sha256'] ?? '') !== hash('sha256', $entry['body'])
            || ($binding['source_ref_sha256'] ?? '') !== hash('sha256', $entry['source_ref'])) {
            return false;
        }
        $approvalCount++;
        return true;
    };
    $first = new KiComEngramAuthenticatedBridge(
        $store, static fn(): array => $actorA, $oneTimeApproval
    );
    $saved = $first->remember($entry);
    $pass(strlen($saved['id']) === 32 && $saved['revision'] === 1 && $approvalCount === 1,
        'synthetic approved write persisted with one-time exact-record consent');
    $query = ['namespace' => 'project', 'query' => 'violet locomotive', 'limit' => 5];
    $current = $first->recall($query);
    $pass($current['count'] === 1 && $current['records'][0]['body'] === $entry['body'],
        'first authorized instance reads scoped engram');

    // Close the first instance and reopen the SQLite database as if an
    // independent, new conversation had started with no shared chat context.
    unset($current, $first, $store);
    $store2 = new KiComEngramStore($root . '/private', $root . '/web');
    $second = new KiComEngramAuthenticatedBridge(
        $store2, static fn(): array => $actorA, static fn(array $binding): bool => false
    );
    $afterRestart = $second->recall($query);
    $pass($afterRestart['count'] === 1
        && $afterRestart['records'][0]['id'] === $saved['id']
        && $afterRestart['records'][0]['body'] === $entry['body'],
        'independent new bridge instance recovers committed memory after reopen');
    $pass(($store2->health()['quick_check'] ?? null) === 'ok',
        'persistent synthetic store retains SQLite integrity');

    $other = new KiComEngramAuthenticatedBridge(
        $store2, static fn(): array => $actorB, static fn(array $binding): bool => false
    );
    $pass($other->recall($query)['count'] === 0, 'separate synthetic subject cannot read first subject');
    $deny(static fn() => $second->recall($query + ['subject' => 'synthetic-b']),
        'request-supplied subject impersonation rejected');
    $deny(static fn() => $second->recall(['namespace'=>'other','query'=>'violet','limit'=>1]),
        'unapproved namespace read rejected');
    $deny(static fn() => $second->recall(['namespace'=>'project','query'=>'violet','limit'=>100]),
        'unbounded retrieval rejected');
    $deny(static fn() => $second->recall(['namespace'=>'project','query'=>'violet']),
        'incomplete query rejected');
    $deny(static fn() => $second->remember($entry),
        'second write without fresh exact-record consent rejected');
    $deny(static fn() => $second->remember($entry + ['subject'=>'synthetic-b']),
        'write request subject injection rejected');
    $readOnly = $actorA;
    $readOnly['engram_rights'] = ['engram.read'];
    $reader = new KiComEngramAuthenticatedBridge(
        $store2, static fn(): array => $readOnly, static fn(array $binding): bool => true
    );
    $pass($reader->recall($query)['count'] === 1, 'read-only actor may retrieve an approved scope');
    $deny(static fn() => $reader->remember($entry), 'read-only actor cannot write even with fake consent');
    $writeOnly = $actorA;
    $writeOnly['engram_rights'] = ['engram.write'];
    $writer = new KiComEngramAuthenticatedBridge(
        $store2, static fn(): array => $writeOnly, static fn(array $binding): bool => false
    );
    $deny(static fn() => $writer->recall($query), 'write-only actor cannot retrieve memory');
    $unverified = $actorA;
    $unverified['verified'] = false;
    $denied = new KiComEngramAuthenticatedBridge(
        $store2, static fn(): array => $unverified, static fn(array $binding): bool => true
    );
    $deny(static fn() => $denied->recall($query), 'unverified identity cannot retrieve memory');
    $deny(static fn() => $denied->remember($entry), 'unverified identity cannot ingest memory');
    $pass($second->recall($query)['count'] === 1,
        'denied requests leave original approved engram intact');
    echo "KICOM_ENGRAM_AUTHENTICATED_BRIDGE_TESTS_PASSED=$bridgeChecks\n";
} finally {
    unset($denied, $writer, $reader, $other, $second, $store2, $first, $store);
    $cleanup($root);
}
