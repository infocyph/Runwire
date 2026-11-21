<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/StructuralWindowBench.php';

it('compares identical PSR-15 responses across prebuilt, legacy, and setup benchmark subjects', function (): void {
    $bench = new StructuralWindowBench();
    $bench->setUp();

    expect($bench->benchPsrPrebuiltHandle())->toBe(204)
        ->and($bench->benchPsrReferenceRebuild())->toBe(204)
        ->and($bench->benchPsrPrebuiltConstruction())->toBe(204)
        ->and($bench->benchPsrPrebuiltHandle())->toBe(204);
});
