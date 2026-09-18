<?php
declare(strict_types=1);

require_once __DIR__.'/CellEvolutionReadiness.php';

/**
 * Read-only diagnostic adapter for evolution evidence readiness.
 *
 * This deliberately stays outside CellLiving::status()/doctor() until the
 * evidence model has accumulated enough observation history. It performs no
 * promotion, mutation, remote probing, authority change or healing.
 */
function kicomCellEvolutionReadinessStatus(string $storageDir): array
{
    $result=(new KiComExpansionCellEvolutionReadiness($storageDir))->evaluate();
    return [
        'ok'=>!empty($result['ok']),
        'code'=>(string)($result['code']??'CELL_EVOLUTION_EVIDENCE_UNKNOWN'),
        'evidence_ready'=>(bool)($result['evidence_ready']??false),
        'promotion_performed'=>false,
        'authority_changed'=>false,
        'observed'=>(array)($result['observed']??[]),
        'thresholds'=>(array)($result['thresholds']??[]),
        'reasons'=>(array)($result['reasons']??[]),
        'note'=>'Diagnostic only. Executable evolution promotion remains deferred and separate.'
    ];
}

if(PHP_SAPI==='cli'&&isset($argv[1])){
    echo json_encode(kicomCellEvolutionReadinessStatus((string)$argv[1]),JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
}
