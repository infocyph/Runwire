<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/websocket_evidence.php';

it('parses finite WebSocket benchmark options and retains the configured defaults', function (): void {
    expect(wsOptions(['port' => '8080']))->toBe([
        'port' => 8080,
        'concurrency' => 8,
        'duration' => 3.0,
        'trials' => 5,
        'soak_seconds' => 0.0,
    ])->and(wsOptions([
        'port' => '65535',
        'concurrency' => '256',
        'duration' => '0.5',
        'trials' => '2',
        'soak-seconds' => false,
    ]))->toBe([
        'port' => 65535,
        'concurrency' => 256,
        'duration' => 0.5,
        'trials' => 2,
        'soak_seconds' => 0.0,
    ]);
});

it('rejects repeated WebSocket options and non-finite numeric inputs', function (string $field, mixed $value): void {
    $options = ['port' => '8080', $field => $value];
    expect(fn() => wsOptions($options))->toThrow(InvalidArgumentException::class);
})->with([
    ['port', ['8080', '8081']],
    ['concurrency', ['8', '16']],
    ['trials', ['5', '10']],
    ['duration', '1e309'],
    ['duration', 'NaN'],
    ['soak-seconds', 'invalid'],
    ['port', '0'],
    ['port', '65536'],
    ['concurrency', '257'],
]);

it('keeps malformed peer frames from becoming valid WebSocket evidence', function (string $wire): void {
    $stream = fopen('php://temp', 'w+b');
    \PHPUnit\Framework\Assert::assertIsResource($stream);
    try {
        fwrite($stream, $wire);
        rewind($stream);
        expect(fn() => wsReadFrame($stream))->toThrow(WebSocketEvidenceProtocolFailure::class);
    } finally {
        fclose($stream);
    }
})->with([
    ["\xC1\x00"],
    ["\x81\x80"],
    ["\x82\x7E\x00\x01"],
    ["\x82\x7F\x01\x00\x00\x00\x00\x01\x00\x00"],
    ["\x82\x7F" . pack('NN', 0, 1_048_577)],
    ["\x09\x00"],
]);

it('marks incomplete child evidence as an error instead of empty successful activity', function (): void {
    foreach ([null, false, '', '{}', '{invalid'] as $raw) {
        $record = wsChildRecord(0, $raw);
        expect($record['errors'])->toBe(1)
            ->and($record['successful_messages'])->toBe(0)
            ->and($record['error_sample'])->not->toBe('');
    }
});

it('validates WebSocket worker counters against their measured latency samples', function (): void {
    $record = [
        'messages' => 2, 'successful_messages' => 2, 'pings' => 1,
        'errors' => 0, 'timeouts' => 0, 'validation_failures' => 0,
        'latencies' => [1.5, 2], 'error_sample' => '',
    ];
    expect(wsChildRecord(0, json_encode($record, JSON_THROW_ON_ERROR))['latencies'])->toBe([1.5, 2.0]);
    $record['latencies'] = [1.5];
    expect(wsChildRecord(0, json_encode($record, JSON_THROW_ON_ERROR))['errors'])->toBe(1);
    $record['latencies'] = [1.5, 'invalid'];
    expect(wsChildRecord(0, json_encode($record, JSON_THROW_ON_ERROR))['errors'])->toBe(1);
});
