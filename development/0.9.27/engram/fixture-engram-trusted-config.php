<?php
declare(strict_types=1);
// Synthetic offline-only fixture. A real provider must be first-party reviewed,
// configured outside all public web roots, and loaded only by trusted bootstrap.
function kicomEngramDevTrustedConfig(): array
{
    $GLOBALS['engram_fixture_config_reads']++;
    return $GLOBALS['engram_fixture_config'];
}
