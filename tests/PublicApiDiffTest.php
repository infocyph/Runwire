<?php

declare(strict_types=1);

it('diffs immutable PHP source API signatures deterministically without requiring Composer', function (): void {
    $path = dirname(__DIR__) . '/tools/public-api-diff.php';
    expect(is_file($path))->toBeTrue();

    $first = tempnam(sys_get_temp_dir(), 'runwire-api-a-');
    $second = tempnam(sys_get_temp_dir(), 'runwire-api-b-');
    expect($first)->toBeString()
        ->and($second)->toBeString();

    $base = [
        'schemaVersion' => 1,
        'sourceRevision' => str_repeat('a', 40),
        'symbols' => [
            'Example\\Legacy' => [
                'kind' => 'class',
                'methods' => ['old' => 'public function old(): void', 'run' => 'public function run(): void'],
                'publicConstants' => ['public const int V = 1;'],
                'enumCases' => [],
                'sha256' => str_repeat('0', 64),
            ],
            'Example\\Removed' => [
                'kind' => 'interface',
                'methods' => [],
                'publicConstants' => [],
                'enumCases' => [],
                'sha256' => str_repeat('2', 64),
            ],
            'Example\\Status' => [
                'kind' => 'enum',
                'methods' => [],
                'publicConstants' => [],
                'enumCases' => ['case OLD;'],
                'sha256' => str_repeat('4', 64),
            ],
        ],
    ];
    $current = [
        'schemaVersion' => 1,
        'sourceRevision' => str_repeat('b', 40),
        'symbols' => [
            'Example\\Legacy' => [
                'kind' => 'class',
                'methods' => ['run' => 'public function run(): int'],
                'publicConstants' => ['public const int V = 2;'],
                'enumCases' => [],
                'sha256' => str_repeat('1', 64),
            ],
            'Example\\Status' => [
                'kind' => 'enum',
                'methods' => [],
                'publicConstants' => [],
                'enumCases' => ['case NEW;'],
                'sha256' => str_repeat('5', 64),
            ],
            'Example\\Added' => [
                'kind' => 'class',
                'methods' => [],
                'publicConstants' => [],
                'enumCases' => [],
                'sha256' => str_repeat('6', 64),
            ],
        ],
    ];

    try {
        file_put_contents($first, json_encode($base, JSON_THROW_ON_ERROR));
        file_put_contents($second, json_encode($current, JSON_THROW_ON_ERROR));
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($path) . ' '
            . escapeshellarg($first) . ' ' . escapeshellarg($second);
        $lines = [];
        $status = 0;
        exec($command . ' 2>&1', $lines, $status);
        expect($status)->toBe(0, implode("\n", $lines));
        $diff = json_decode(implode("\n", $lines), true, 32, JSON_THROW_ON_ERROR);

        expect($diff['baselineRevision'])->toBe(str_repeat('a', 40))
            ->and($diff['candidateRevision'])->toBe(str_repeat('b', 40))
            ->and($diff['breakingCount'])->toBe(2)
            ->and($diff['additionCount'])->toBe(1)
            ->and($diff['breaking'])->toContain(['type' => 'removed_type', 'symbol' => 'Example\\Removed'])
            ->and($diff['breaking'])->toContain(['type' => 'removed_method', 'symbol' => 'Example\\Legacy::old'])
            ->and($diff['added'])->toContain(['type' => 'added_type', 'symbol' => 'Example\\Added']);
        expect(array_column($diff['changed'], 'type'))->toContain(
            'method_signature',
            'removed_or_changed_constant',
            'removed_or_changed_enum_case',
            'source_changed',
        );

        $again = [];
        exec($command . ' 2>&1', $again, $status);
        expect($status)->toBe(0)
            ->and($again)->toBe($lines);
    } finally {
        if (is_string($first) && is_file($first)) {
            unlink($first);
        }
        if (is_string($second) && is_file($second)) {
            unlink($second);
        }
    }
});
