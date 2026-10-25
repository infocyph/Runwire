<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Internal\QuicWorkerCommand;
use Infocyph\Runwire\Network\Internal\QuicWorkerSession;

/** @return array{QuicWorkerSession, object} */
function runwireNativeQuicWorkerFixture(int $limit = 2, bool $partialWrites = false): array
{
    $worker = new QuicWorkerSession();
    $native = new class($partialWrites) {
        public function __construct(private readonly bool $partialWrites) {}

        public bool $closed = false;

        public bool $lastBidirectional = true;

        /** @var list<object> */
        public array $incoming = [];

        public function acceptStream(): ?object
        {
            return array_shift($this->incoming);
        }

        public function openStream(bool $bidirectional): object
        {
            $this->lastBidirectional = $bidirectional;

            return new class($this->partialWrites) {
                public function __construct(private readonly bool $partialWrites) {}

                private string $buffer = '';

                private bool $finished = false;

                public function write(string $data, bool $fin): int
                {
                    $accepted = $this->partialWrites && strlen($data) > 1
                        ? strlen($data) - 1
                        : strlen($data);
                    $this->buffer .= substr($data, 0, $accepted);
                    $this->finished = $fin && $accepted === strlen($data);

                    return $accepted;
                }

                public function read(int $limit): ?string
                {
                    if ($this->buffer !== '') {
                        $result = substr($this->buffer, 0, $limit);
                        $this->buffer = substr($this->buffer, strlen($result));

                        return $result;
                    }

                    return $this->finished ? null : '';
                }

                public function reset(): void
                {
                    $this->buffer = '';
                    $this->finished = true;
                }
            };
        }

        public function handleEvents(): void {}

        public function close(): void
        {
            $this->closed = true;
        }
    };
    (new ReflectionProperty($worker, 'connection'))->setValue($worker, $native);
    (new ReflectionProperty($worker, 'epoch'))->setValue($worker, 1);
    (new ReflectionProperty($worker, 'maxOpenStreams'))->setValue($worker, $limit);

    return [$worker, $native];
}

it('preserves worker-owned bidirectional QUIC payloads across independent IPC commands', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture();
    $open = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'open_bidi')]);
    expect($open)->toBe(['stream' => 1, 'bidirectional' => true]);

    $written = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'write', 1, "one\x00two", true)]);
    $received = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(3, 'read', 1)]);
    $eof = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(4, 'read', 1)]);
    $closed = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(5, 'close')]);

    expect($written)->toBe(['written' => 7])
        ->and(base64_decode($received['data'], true))->toBe("one\x00two")
        ->and($received['eof'])->toBeFalse()
        ->and($eof['eof'])->toBeTrue()
        ->and($closed)->toBe(['closed' => true])
        ->and($native->closed)->toBeTrue();
});

it('fails closed if a client-initiated unidirectional QUIC stream is read from', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture();
    $open = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'open_uni')]);
    expect($open)->toBe(['stream' => 1, 'bidirectional' => false])
        ->and($native->lastBidirectional)->toBeFalse();
    expect($worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'write', 1, 'uni', true)]))
        ->toBe(['written' => 3]);

    expect(fn() => $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(3, 'read', 1)]))
        ->toThrow(InvalidArgumentException::class, 'unidirectional stream')
        ->and($native->closed)->toBeTrue();
});

it('limits total native QUIC stream admissions and terminates the owning connection on overflow', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 1);
    $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'open_bidi')]);
    expect(fn() => $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'open_bidi')]))
        ->toThrow(RuntimeException::class, 'admission limit exceeded')
        ->and($native->closed)->toBeTrue();
});

it('rejects stale logical streams and never accepts commands from a previous native epoch', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture();
    expect(fn() => $worker->execute(['epoch' => 2, 'command' => QuicWorkerCommand::encode(1, 'open_bidi')]))
        ->toThrow(RuntimeException::class, 'different epoch')
        ->and($native->closed)->toBeFalse();

    $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'open_bidi')]);
    $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(3, 'reset', 1)]);
    expect(fn() => $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(4, 'write', 1, 'stale')]))
        ->toThrow(RuntimeException::class, 'Unknown or released')
        ->and($native->closed)->toBeTrue();
});

it('fails closed when a FIN-marked native QUIC write reports a partial send', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(partialWrites: true);
    $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'open_bidi')]);

    expect(fn() => $worker->execute([
        'epoch' => 1,
        'command' => QuicWorkerCommand::encode(2, 'write', 1, 'complete-payload', true),
    ]))->toThrow(RuntimeException::class, 'invalid write length')
        ->and($native->closed)->toBeTrue();
});

it('rejects duplicate and out-of-order native QUIC worker commands without reusing stream state', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture();
    $opened = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(4, 'open_bidi')]);
    expect($opened['stream'])->toBe(1);

    expect(fn() => $worker->execute([
        'epoch' => 1,
        'command' => QuicWorkerCommand::encode(4, 'read', 1),
    ]))->toThrow(RuntimeException::class, 'replayed')
        ->and($native->closed)->toBeTrue();
});

it('allows command sequence gaps from canceled queued jobs while preserving worker ownership', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture();
    $opened = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'open_bidi')]);
    $written = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(5, 'write', 1, 'payload', true)]);
    $received = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(9, 'read', 1)]);

    expect($opened)->toBe(['stream' => 1, 'bidirectional' => true])
        ->and($written)->toBe(['written' => 7])
        ->and(base64_decode($received['data'], true))->toBe('payload')
        ->and($native->closed)->toBeFalse();
});

it('releases a finished native stream slot while maintaining monotonically increasing stream IDs', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 1);
    expect($worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'open_bidi')]))
        ->toBe(['stream' => 1, 'bidirectional' => true]);
    $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'write', 1, 'ok', true)]);
    $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(3, 'read', 1)]);
    expect($worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(4, 'read', 1)]))
        ->toBe(['data' => '', 'eof' => true])
        ->and($worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(5, 'open_bidi')]))
        ->toBe(['stream' => 2, 'bidirectional' => true])
        ->and($native->closed)->toBeFalse();
});

it('resets one of two active QUIC streams without aborting its sibling', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 2);
    $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'open_bidi')]);
    $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'open_bidi')]);
    expect($worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(3, 'reset', 1)]))
        ->toBe(['reset' => true])
        ->and($worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(4, 'write', 2, 'sibling', true)]))
        ->toBe(['written' => 7]);

    $received = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(5, 'read', 2)]);
    expect(base64_decode($received['data'], true))->toBe('sibling')
        ->and($native->closed)->toBeFalse()
        ->and($worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(6, 'open_bidi')]))
        ->toBe(['stream' => 3, 'bidirectional' => true]);
});


it('bounds native QUIC stream churn across 64 resets with an active sibling', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 2);
    $nextCommand = 1;
    $first = $worker->execute([
        'epoch' => 1,
        'command' => QuicWorkerCommand::encode($nextCommand++, 'open_bidi'),
    ]);
    $second = $worker->execute([
        'epoch' => 1,
        'command' => QuicWorkerCommand::encode($nextCommand++, 'open_bidi'),
    ]);
    $active = [$first['stream'], $second['stream']];
    for ($i = 0; $i < 64; ++$i) {
        $retired = $i % 2;
        $reset = $worker->execute([
            'epoch' => 1,
            'command' => QuicWorkerCommand::encode($nextCommand++, 'reset', $active[$retired]),
        ]);
        expect($reset)->toBe(['reset' => true]);

        $opened = $worker->execute([
            'epoch' => 1,
            'command' => QuicWorkerCommand::encode($nextCommand++, 'open_bidi'),
        ]);
        expect($opened['stream'])->toBe($i + 3);
        $active[$retired] = $opened['stream'];

        $sibling = $active[1 - $retired];
        $payload = 'sibling-round-' . $i;
        $written = $worker->execute([
            'epoch' => 1,
            'command' => QuicWorkerCommand::encode($nextCommand++, 'write', $sibling, $payload, false),
        ]);
        $received = $worker->execute([
            'epoch' => 1,
            'command' => QuicWorkerCommand::encode($nextCommand++, 'read', $sibling),
        ]);
        expect($written)->toBe(['written' => strlen($payload)])
            ->and(base64_decode($received['data'], true))->toBe($payload)
            ->and($received['eof'])->toBeFalse()
            ->and($native->closed)->toBeFalse();
    }
    expect($worker->execute([
        'epoch' => 1,
        'command' => QuicWorkerCommand::encode($nextCommand, 'close'),
    ]))->toBe(['closed' => true])
        ->and($native->closed)->toBeTrue();
});

it('preserves two native QUIC siblings under non-FIN partial writes followed by full FIN chunks', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 2, partialWrites: true);
    $streamA = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'open_bidi')])['stream'];
    $streamB = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'open_bidi')])['stream'];
    $payloadA = 'alpha-flow-window';
    $payloadB = 'bravo-stream-window';

    $acceptedA = $worker->execute([
        'epoch' => 1, 'command' => QuicWorkerCommand::encode(3, 'write', $streamA, $payloadA, false),
    ])['written'];
    $acceptedB = $worker->execute([
        'epoch' => 1, 'command' => QuicWorkerCommand::encode(4, 'write', $streamB, $payloadB, false),
    ])['written'];
    expect($acceptedA)->toBe(strlen($payloadA) - 1)
        ->and($acceptedB)->toBe(strlen($payloadB) - 1)
        ->and($native->closed)->toBeFalse();

    $lastA = substr($payloadA, $acceptedA);
    $lastB = substr($payloadB, $acceptedB);
    expect($worker->execute([
        'epoch' => 1, 'command' => QuicWorkerCommand::encode(5, 'write', $streamA, $lastA, true),
    ]))->toBe(['written' => strlen($lastA)])
        ->and($worker->execute([
            'epoch' => 1, 'command' => QuicWorkerCommand::encode(6, 'write', $streamB, $lastB, true),
        ]))->toBe(['written' => strlen($lastB)]);

    $replyA = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(7, 'read', $streamA)]);
    $replyB = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(8, 'read', $streamB)]);
    expect(base64_decode($replyA['data'], true))->toBe($payloadA)
        ->and(base64_decode($replyB['data'], true))->toBe($payloadB)
        ->and($replyA['eof'])->toBeFalse()
        ->and($replyB['eof'])->toBeFalse()
        ->and($worker->execute([
            'epoch' => 1, 'command' => QuicWorkerCommand::encode(9, 'read', $streamA),
        ])['eof'])->toBeTrue()
        ->and($worker->execute([
            'epoch' => 1, 'command' => QuicWorkerCommand::encode(10, 'read', $streamB),
        ])['eof'])->toBeTrue()
        ->and($native->closed)->toBeFalse();
});

it('reuses four native QUIC slots through 24 concurrent partial-write and complete-FIN cycles', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 4, partialWrites: true);
    $commandId = 1;
    for ($cycle = 0; $cycle < 24; ++$cycle) {
        $streams = [];
        foreach (range(0, 3) as $sibling) {
            $opened = $worker->execute([
                'epoch' => 1,
                'command' => QuicWorkerCommand::encode($commandId++, 'open_bidi'),
            ]);
            $streams[] = ['id' => $opened['stream'], 'payload' => 'cycle-' . $cycle . '-sibling-' . $sibling];
        }
        foreach ($streams as $stream) {
            $data = $stream['payload'];
            $sent = $worker->execute([
                'epoch' => 1,
                'command' => QuicWorkerCommand::encode($commandId++, 'write', $stream['id'], $data, false),
            ])['written'];
            expect($sent)->toBe(strlen($data) - 1);
        }
        foreach ($streams as $stream) {
            $last = substr($stream['payload'], -1);
            expect($worker->execute([
                'epoch' => 1,
                'command' => QuicWorkerCommand::encode($commandId++, 'write', $stream['id'], $last, true),
            ]))->toBe(['written' => 1]);
        }
        foreach ($streams as $stream) {
            $read = $worker->execute([
                'epoch' => 1,
                'command' => QuicWorkerCommand::encode($commandId++, 'read', $stream['id']),
            ]);
            expect(base64_decode($read['data'], true))->toBe($stream['payload'])
                ->and($read['eof'])->toBeFalse()
                ->and($worker->execute([
                    'epoch' => 1,
                    'command' => QuicWorkerCommand::encode($commandId++, 'read', $stream['id']),
                ])['eof'])->toBeTrue();
        }
        expect($native->closed)->toBeFalse();
    }
    expect($worker->execute([
        'epoch' => 1, 'command' => QuicWorkerCommand::encode($commandId, 'close'),
    ]))->toBe(['closed' => true])
        ->and($native->closed)->toBeTrue();
});

it('supports an empty FIN and then safely reclaims the stream slot after EOF', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 1, partialWrites: true);
    $opened = $worker->execute([
        'epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'open_bidi'),
    ]);
    expect($worker->execute([
        'epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'write', $opened['stream'], '', true),
    ]))->toBe(['written' => 0])
        ->and($worker->execute([
            'epoch' => 1, 'command' => QuicWorkerCommand::encode(3, 'read', $opened['stream']),
        ]))->toBe(['data' => '', 'eof' => true])
        ->and($worker->execute([
            'epoch' => 1, 'command' => QuicWorkerCommand::encode(4, 'open_bidi'),
        ])['stream'])->toBe(2)
        ->and($native->closed)->toBeFalse();
});



it('accepts peer QUIC unidirectional streams without confusing local send-only ownership', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 2);
    $pending = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'accept')]);
    expect($pending)->toBe(['pending' => true])
        ->and($native->closed)->toBeFalse();

    $native->incoming[] = new class {
        private bool $sent = false;

        public function isBidirectional(): bool
        {
            return false;
        }

        public function read(int $limit): ?string
        {
            if ($limit < 1) {
                return '';
            }
            if ($this->sent) {
                return null;
            }
            $this->sent = true;

            return 'peer-control-settings';
        }

        public function reset(): void {}

        public function write(string $data, bool $fin): int
        {
            return $fin ? strlen($data) : 0;
        }
    };
    $accepted = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'accept')]);
    expect($accepted)->toBe(['stream' => 1, 'bidirectional' => false, 'incoming' => true]);
    $read = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(3, 'read', 1)]);
    expect(base64_decode($read['data'], true))->toBe('peer-control-settings')
        ->and($read['eof'])->toBeFalse()
        ->and($worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(4, 'read', 1)]))
        ->toBe(['data' => '', 'eof' => true])
        ->and($worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(5, 'open_bidi')]))
        ->toBe(['stream' => 2, 'bidirectional' => true])
        ->and($native->closed)->toBeFalse();
});

it('rejects writes to peer-created receive-only streams and invalid accept commands', function (): void {
    expect(fn() => QuicWorkerCommand::encode(1, 'accept', 5))
        ->toThrow(InvalidArgumentException::class, 'Invalid or excessive');

    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 1);
    $native->incoming[] = new class {
        public function isBidirectional(): bool
        {
            return false;
        }

        public function read(int $limit): ?string
        {
            return $limit > 0 ? '' : null;
        }

        public function reset(): void {}

        public function write(string $bytes, bool $fin): int
        {
            return $fin ? strlen($bytes) : 0;
        }
    };
    $accepted = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'accept')]);
    expect($accepted['stream'])->toBe(1)
        ->and(fn() => $worker->execute([
            'epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'write', 1, 'forbidden'),
        ]))->toThrow(InvalidArgumentException::class, 'receive-only')
        ->and($native->closed)->toBeTrue();
});


it('preserves a stream and its sibling during bounded partial writes before FIN', function (): void {
    [$worker, $native] = runwireNativeQuicWorkerFixture(limit: 2, partialWrites: true);
    $first = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(1, 'open_bidi')]);
    $second = $worker->execute(['epoch' => 1, 'command' => QuicWorkerCommand::encode(2, 'open_bidi')]);
    $payload = 'partial-write-flow';
    $offset = 0;
    $id = 3;
    while ($offset < strlen($payload)) {
        $next = $worker->execute([
            'epoch' => 1,
            'command' => QuicWorkerCommand::encode($id++, 'write', $first['stream'], substr($payload, $offset)),
        ]);
        expect($next['written'])->toBeGreaterThan(0);
        $offset += $next['written'];
    }
    expect($worker->execute([
        'epoch' => 1, 'command' => QuicWorkerCommand::encode($id++, 'write', $first['stream'], '', true),
    ]))->toBe(['written' => 0])
        ->and($worker->execute([
            'epoch' => 1, 'command' => QuicWorkerCommand::encode($id++, 'write', $second['stream'], 'alive', true),
        ]))->toBe(['written' => 5]);
    $received = '';
    do {
        $result = $worker->execute([
            'epoch' => 1, 'command' => QuicWorkerCommand::encode($id++, 'read', $first['stream']),
        ]);
        $received .= base64_decode($result['data'], true);
    } while (!$result['eof']);
    expect($received)->toBe($payload)->and($native->closed)->toBeFalse();
});
