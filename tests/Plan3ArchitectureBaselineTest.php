<?php

declare(strict_types=1);

it('documents the U00 architecture baseline and every historical completed batch', function (): void {
    $root = dirname(__DIR__);
    $document = file_get_contents($root . '/docs/plans/evidence/runwire-3.0/u00-architecture-baseline.md');
    expect($document)->toBeString();

    foreach (['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9'] as $batch) {
        expect($document)->toContain('| ' . $batch . ' ');
    }
    foreach ([
        '7e9bf20ad0737a187dfed77b723791b141b36378',
        '20 historical unchecked',
        '75 historical checked',
        'RuntimeCapabilities',
        'LoopInterface',
        'Connection',
        'Swoole/OpenSwoole',
        'PHP-FPM',
        'RoadRunner',
        'FrankenPHP',
        'Read the Docs',
        'U00.09 remains OPEN',
    ] as $value) {
        expect($document)->toContain($value);
    }

    foreach ([
        'src/Network/Connection.php',
        'src/Coroutine/Network/AsyncConnection.php',
        'src/Loop/LoopInterface.php',
        'src/Network/TlsOptions.php',
        'src/RuntimeCapabilities.php',
        'src/Runtime/RuntimeDriverResolver.php',
        'src/RuntimeOptions.php',
    ] as $source) {
        expect(is_file($root . '/' . $source))->toBeTrue();
    }
});
