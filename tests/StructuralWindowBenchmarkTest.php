<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Benchmarks\StructuralWindowBench;

require_once __DIR__ . '/../benchmarks/StructuralWindowBench.php';

it('compares identical PSR-15 responses across prebuilt, legacy, and setup benchmark subjects', function (): void {
    $bench = new StructuralWindowBench();

    expect($bench->benchPsrPrebuiltHandle())->toBe(204)
        ->and($bench->benchPsrReferenceRebuild())->toBe(204)
        ->and($bench->benchPsrPrebuiltConstruction())->toBe(204)
        ->and($bench->benchPsrPrebuiltHandle())->toBe(204);
});
