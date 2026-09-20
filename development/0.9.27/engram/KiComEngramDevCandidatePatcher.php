<?php
declare(strict_types=1);

/**
 * Offline, one-baseline candidate preparation helper.
 * It writes only two disposable DEV-only candidate copies into a new empty
 * operator-provided test directory. Never modifies trusted R3 source files,
 * a live KiCom installation, the recovery kernel, or the genome.
 * Operator review / protected ordinary release verifier still required.
 */
final class KiComEngramDevCandidatePatcher
{
    private const SOURCE_HASHES = [
        'DevSession.php' => '72e1c750de8eeccc1b2144d4869308a0f6a1e9de07ce882a6d3b8604eaf28163',
        'DevRouter.php' => '2937be48505121c12a7f110fdf77c8ac6d4805b2e811b25431ba915c3e0b1b09',
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
