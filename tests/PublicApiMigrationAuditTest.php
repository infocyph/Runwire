<?php

declare(strict_types=1);

it('fails closed on unreviewed public API migration changes and malformed inventories', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-migration-audit-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0700))->toBeTrue();
    $diffPath = $directory . '/diff.json';
    $policyPath = $directory . '/policy.json';
    $tool = dirname(__DIR__) . '/tools/public-api-migration-audit.php';
    $baseline = str_repeat('a', 40);
    $candidate = str_repeat('b', 40);
    $policy = [
        'schemaVersion' => 1,
        'publishedRevision' => $baseline,
        'sourceMethodRelocations' => [],
        'reviewedSignatureChanges' => [],
    ];
    $diff = [
        'schemaVersion' => 1,
        'baselineRevision' => $baseline,
        'candidateRevision' => $candidate,
        'propertyInventoryCompared' => true,
        'breakingCount' => 0,
        'changeCount' => 0,
        'additionCount' => 0,
        'breaking' => [],
        'changed' => [],
        'added' => [],
    ];

    $run = static function (array $changes, array $approval) use ($diffPath, $policyPath, $tool): array {
        file_put_contents($diffPath, json_encode($changes, JSON_THROW_ON_ERROR));
        file_put_contents($policyPath, json_encode($approval, JSON_THROW_ON_ERROR));
        $output = [];
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool)
            . ' ' . escapeshellarg($diffPath) . ' ' . escapeshellarg($policyPath) . ' 2>&1',
            $output,
            $status,
        );

        return [$status, implode("\n", $output)];
    };

    try {
        [$status, $output] = $run($diff, $policy);
        expect($status)->toBe(0);
        $accepted = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        expect($accepted['reviewed'])->toBeTrue()
            ->and($accepted['behavioralCompatibilityCertified'])->toBeFalse()
            ->and($accepted['traitRelocations'])->toBe(0);

        $changes = [
            'incomplete property inventory' => ['propertyInventoryCompared' => false],
            'wrong published comparator' => ['baselineRevision' => str_repeat('c', 40)],
            'invalid candidate revision' => ['candidateRevision' => ['not-a-sha']],
            'removed type' => [
                'breaking' => [['type' => 'removed_type', 'symbol' => 'Example\Legacy']],
                'breakingCount' => 1,
            ],
            'removed public field' => [
                'breaking' => [['type' => 'removed_property', 'symbol' => 'Example\Legacy::$secret']],
                'breakingCount' => 1,
            ],
            'changed public declaration' => [
                'changed' => [['type' => 'property_declaration', 'symbol' => 'Example\Legacy::$name']],
                'changeCount' => 1,
            ],
            'unreviewed method signature' => [
                'changed' => [[
                    'type' => 'method_signature',
                    'symbol' => 'Example\Legacy::open',
                    'before' => 'public function open():void',
                    'after' => 'public function open(int $flags = 0):void',
                ]],
                'changeCount' => 1,
            ],
            'incorrect reported counts' => ['additionCount' => 1],
        ];
        foreach ($changes as $name => $change) {
            [$status] = $run(array_replace($diff, $change), $policy);
            expect($status)->not->toBe(0, $name);
        }
        [$status] = $run($diff, array_replace($policy, [
            'reviewedSignatureChanges' => [[
                'symbol' => 'Example\Legacy::__construct',
                'beforeSha256' => ['not-a-hex-string'],
                'afterSha256' => str_repeat('0', 64),
            ]],
        ]));
        expect($status)->not->toBe(0);
    } finally {
        foreach ([$diffPath, $policyPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
});
