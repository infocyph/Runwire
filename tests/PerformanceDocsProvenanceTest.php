<?php

declare(strict_types=1);

it('documents quantified release limits with executable sources but no unmeasured performance claims', function (): void {
    $root = dirname(__DIR__);
    $rst = file_get_contents($root . '/docs/performance-security-3.0.rst');
    expect($rst)->toBeString()
        ->toContain('required measurement criteria', 'not claims')
        ->toContain('five', '2.5 percent', '1800-second', '1740 seconds');

    foreach ([
        'benchmarks/matched_release_certification.sh',
        'benchmarks/release_topology_inventory.py',
        'benchmarks/soak_runtime_telemetry.php',
        'src/Filesystem/AsyncFileSystem.php',
        'src/Network/PoolIsolationKey.php',
        'tests/PoolIsolationKeyTest.php',
    ] as $source) {
        expect(is_file($root . '/' . $source))->toBeTrue()
            ->and($rst)->toContain($source);
    }

    expect($rst)->toContain('must not')
        ->and($rst)->toContain('immutable workflow-run evidence')
        ->and($rst)->not->toContain('Runwire 3.0 is faster by');
});
