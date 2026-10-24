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

        public function openStream(bool $bidirectional): object
        {
            $this->lastBidirectional = $bidirectional;

            return new class($this->partialWrites) {
                public function __construct(private readonly bool $partialWrites) {}

                private string $buffer = '';

                private bool $finished = false;

                public function write(string $data, bool $fin): int
                {
                    $this->buffer .= $data;
                    $this->finished = $fin;

                    return $this->partialWrites ? max(0, strlen($data) - 1) : strlen($data);
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
