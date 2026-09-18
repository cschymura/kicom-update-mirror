<?php
declare(strict_types=1);

require_once __DIR__.'/CellEvolutionReadiness.php';

/**
 * Private, read-only adapter for daughter-cell evolution readiness diagnostics.
 * It deliberately exposes no HTTP surface and has no promotion authority.
 */
final class KiComCellEvolutionReadinessAdapter
{
    private KiComCellEvolutionReadiness $evaluator;

    public function __construct(?KiComCellEvolutionReadiness $evaluator=null)
    {
        $this->evaluator=$evaluator ?? new KiComCellEvolutionReadiness();
    }

    /** @return array<string,mixed> */
    public function diagnose(string $livingRoot): array
    {
        $result=$this->evaluator->evaluate($livingRoot);
        return [
            'schema'=>1,
            'kind'=>'evolution-readiness-diagnostic',
            'read_only'=>true,
            'promotion_authority'=>false,
            'promotion_performed'=>false,
            'authority_changed'=>false,
            'result'=>$result,
        ];
    }
}
