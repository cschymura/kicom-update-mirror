<?php
declare(strict_types=1);

/**
 * Offline, one-baseline candidate preparation helper.
 * It writes only narrowly scoped disposable DEV-only candidate copies into a new empty
 * operator-provided test directory. Never modifies trusted R3 source files,
 * a live KiCom installation, the recovery kernel, or the genome.
 * Operator review / protected ordinary release verifier still required.
 */
final class KiComEngramDevCandidatePatcher
{
    private const SOURCE_HASHES = [
        'DevSession.php' => '72e1c750de8eeccc1b2144d4869308a0f6a1e9de07ce882a6d3b8604eaf28163',
        'DevRouter.php' => '2937be48505121c12a7f110fdf77c8ac6d4805b2e811b25431ba915c3e0b1b09',
        'DevHttpAdapter.php' => '77b489a68e201f0e023432d6542dac0bdfbf0827d39c54c94c857390642c8983',
        'DevEndpoint.php' => '2ecc67bbff595b350939e435a34eda8406dd1a33d96b5d6c8cd95dd431eb301b',
    ];

    private static function replaceOnce(string $text, string $needle, string $replacement): string
    {
        if (substr_count($text, $needle) !== 1) {
            throw new RuntimeException('DEV candidate source anchor mismatch');
        }
        return str_replace($needle, $replacement, $text);
    }

    /** @return array<string,string> names and SHA-256s, never private data */
    public static function generate(string $originalDevModules, string $emptyCandidateDirectory): array
    {
        if (!is_dir($originalDevModules) || !is_dir($emptyCandidateDirectory)
            || is_link($emptyCandidateDirectory)
            || (fileperms($emptyCandidateDirectory) & 0077) !== 0) {
            throw new RuntimeException('Candidate directories not valid');
        }
        $items = scandir($emptyCandidateDirectory);
        if ($items !== ['.', '..']) {
            throw new RuntimeException('Candidate output directory must be empty');
        }
        $out = [];
        $preflighted = [];
        foreach (self::SOURCE_HASHES as $name => $expected) {
            $source = $originalDevModules . '/' . $name;
            $raw = @file_get_contents($source);
            if (!is_string($raw) || !hash_equals($expected, hash('sha256', $raw))) {
                throw new RuntimeException('Original R3 source hash mismatch');
            }
            if ($name === 'DevSession.php') {
                $raw = self::replaceOnce(
                    $raw,
                    "        'logs.read',\n    ];",
                    "        'logs.read',\n        'engram.path.probe',\n    ];"
                );
            } elseif ($name === 'DevRouter.php') {
                $raw = self::replaceOnce(
                    $raw,
                    "        'DEV_LOG_READ' => 'logs.read',",
                    "        'DEV_LOG_READ' => 'logs.read',\n        'DEV_ENGRAM_PATH_PROBE' => 'engram.path.probe',"
                );
            }
            if ($name === 'DevEndpoint.php') {
                $raw = self::replaceOnce(
                    $raw,
                    '    $handlers=array_merge(KiComDevRuntimeBindings::handlers(),KiComDevDiagnostics::handlers());',
                    '    $handlers=array_merge(KiComDevRuntimeBindings::handlers(),KiComDevDiagnostics::handlers());' . "\n"
                    . '    // Engram stays unregistered until a trusted bootstrap supplies fixed paths.' . "\n"
                    . "    if (function_exists('kicomEngramDevTrustedConfig')" . "\n"
                    . "        && is_file(__DIR__.'/KiComEngramDevPathHandler.php')" . "\n"
                    . "        && is_file(__DIR__.'/KiComEngramPrivatePathProbe.php')) {" . "\n"
                    . "        if (!class_exists('KiComEngramDevPathHandler', false)) require_once __DIR__.'/KiComEngramDevPathHandler.php';" . "\n"
                    . "        \$handlers=array_merge(\$handlers,KiComEngramDevPathHandler::handlers(" . "\n"
                    . "            static fn(): array => kicomEngramDevTrustedConfig()" . "\n"
                    . "        ));" . "\n"
                    . "    }"
                );
                $raw = self::replaceOnce(
                    $raw,
                    "    if(\$op==='')return ['ok'=>false,'code'=>'DEV_BRIDGE_OPERATION_REQUIRED'];",
                    "    if(\$op==='')return ['ok'=>false,'code'=>'DEV_BRIDGE_OPERATION_REQUIRED'];" . "\n"
                    . "    if(\$op==='DEV_ENGRAM_PATH_PROBE')return ['ok'=>false,'code'=>'DEV_ENGRAM_POST_REQUIRED'];"
                );
            }
            $preflighted[$name] = $raw;
        }
        // Candidate-only local class copies; never load memories or private host files.
        foreach (['KiComEngramDevPathHandler.php','KiComEngramPrivatePathProbe.php'] as $name) {
            $raw = @file_get_contents(__DIR__ . '/' . $name);
            if (!is_string($raw) || !str_starts_with($raw, '<?php')) {
                throw new RuntimeException('DEV module source missing');
            }
            $preflighted[$name] = $raw;
        }
        // Hash-check and patch all trusted sources before writing any output.
        foreach ($preflighted as $name => $raw) {
            $target = $emptyCandidateDirectory . '/' . $name;
            $fh = @fopen($target, 'x+b');
            if ($fh === false) {
                throw new RuntimeException('Could not create candidate');
            }
            try {
                if (fwrite($fh, $raw) !== strlen($raw) || !fflush($fh) || !chmod($target, 0600)) {
                    throw new RuntimeException('Candidate write failed');
                }
            } finally {
                fclose($fh);
            }
            $out[$name] = hash('sha256', $raw);
        }
        return $out;
    }
}
