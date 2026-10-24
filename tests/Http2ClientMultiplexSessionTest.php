<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Internal\Http2ClientMultiplexSession;
use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;

it('shares one H2 preface and HPACK decoder across simultaneously live response streams', function (): void {
    $session = new Http2ClientMultiplexSession(2);
    expect(str_starts_with($session->start(), Http2Connection::CLIENT_PREFACE))->toBeTrue()
        ->and(fn() => $session->request(
            new ClientRequest('GET', 'https://example.test/a'),
            static function (): void {},
            static function (): void {},
            static function (): void {},
        ))->toThrow(RuntimeException::class, 'admission');

    $ack = (new FrameParser())->push($session->receive(FrameWriter::settings()));
    expect($ack)->toHaveCount(1)
        ->and($ack[0]->knownType())->toBe(FrameType::SETTINGS)
        ->and($ack[0]->hasFlag(0x1))->toBeTrue();

    $codes = [];
    $bodies = [];
    $completions = [];
    $request = static function (string $path) use ($session, &$codes, &$bodies, &$completions): array {
        return $session->request(
            new ClientRequest('GET', 'https://example.test/' . $path),
            static function (int $status) use (&$codes, $path): void { $codes[$path] = $status; },
            static function (string $bytes) use (&$bodies, $path): void { $bodies[$path] = ($bodies[$path] ?? '') . $bytes; },
            static function (?Throwable $error) use (&$completions, $path): void { $completions[$path][] = $error; },
        );
    };
    $one = $request('one');
    $three = $request('three');
    expect($one['stream'])->toBe(1)
        ->and($three['stream'])->toBe(3)
        ->and($session->activeCount())->toBe(2);
    expect(fn() => $request('overflow'))->toThrow(RuntimeException::class, 'admission');

    $encoder = new Encoder();
    $headOne = $encoder->encode([[':status', '200'], ['x-dynamic', 'same']]);
    $headThree = $encoder->encode([[':status', '200'], ['x-dynamic', 'same']]);
    $session->receive(new Frame(FrameType::HEADERS->value, 0x4, 1, $headOne));
    $session->receive(new Frame(FrameType::HEADERS->value, 0x4, 3, $headThree));
    $credits = (new FrameParser())->push($session->receive(new Frame(FrameType::DATA->value, 0, 1, 'part')));
    expect($credits)->toHaveCount(2)
        ->and($credits[0]->streamId)->toBe(0)
        ->and($credits[1]->streamId)->toBe(1);
    $finalCredit = (new FrameParser())->push($session->receive(new Frame(FrameType::DATA->value, 0x1, 3, 'three')));
    expect($finalCredit)->toHaveCount(1)->and($finalCredit[0]->streamId)->toBe(0);
    expect($session->activeCount())->toBe(1);
    $session->receive(new Frame(FrameType::DATA->value, 0x1, 1, 'one'));
    expect($session->activeCount())->toBe(0)
        ->and($codes)->toBe(['one' => 200, 'three' => 200])
        ->and($bodies)->toBe(['one' => 'partone', 'three' => 'three'])
        ->and($completions['one'])->toBe([null])
        ->and($completions['three'])->toBe([null]);
});

it('isolates peer RST and client cancellation while rejecting unsafe late frames', function (): void {
    $session = new Http2ClientMultiplexSession(2);
    $session->start();
    $session->receive(FrameWriter::settings());
    $failures = [];
    foreach (['a', 'b'] as $path) {
        $session->request(
            new ClientRequest('GET', 'https://example.test/' . $path),
            static function (): void {},
            static function (): void {},
            static function (?Throwable $error) use (&$failures, $path): void { $failures[$path][] = $error; },
        );
    }
    $reset = (new FrameParser())->push($session->cancel(1));
    expect($reset)->toHaveCount(1)
        ->and($reset[0]->knownType())->toBe(FrameType::RST_STREAM)
        ->and($reset[0]->streamId)->toBe(1)
        ->and($session->activeCount())->toBe(1)
        ->and($failures['a'][0])->toBeInstanceOf(RuntimeException::class);
    $lateCredit = (new FrameParser())->push($session->receive(new Frame(FrameType::DATA->value, 0x1, 1, 'late')));
    expect($lateCredit)->toHaveCount(1)->and($lateCredit[0]->streamId)->toBe(0);
    expect(fn() => $session->receive(new Frame(FrameType::HEADERS->value, 0x4, 1, 'x')))
        ->toThrow(RuntimeException::class, 'unknown');

    $session->receive(new Frame(FrameType::RST_STREAM->value, 0, 3, pack('N', ErrorCode::CANCEL->value)));
    expect($session->activeCount())->toBe(0)
        ->and($failures['b'][0])->toBeInstanceOf(RuntimeException::class);
    $session->close();
    expect($failures['a'])->toHaveCount(1)
        ->and($failures['b'])->toHaveCount(1);
});

it('rejects cross-origin requests, invalid continuation interleaving and unaccepted GOAWAY streams', function (): void {
    $session = new Http2ClientMultiplexSession(3);
    $session->start();
    $session->receive(FrameWriter::settings());
    $failed = [];
    $request = static function (string $uri) use ($session, &$failed): array {
        return $session->request(
            new ClientRequest('GET', $uri),
            static function (): void {},
            static function (): void {},
            static function (?Throwable $error) use (&$failed): void { $failed[] = $error; },
        );
    };
    $request('https://example.test/one');
    expect(fn() => $request('https://another.test/other'))
        ->toThrow(InvalidArgumentException::class, 'verified origin');
    $request('https://example.test/two');
    $session->receive(new Frame(FrameType::HEADERS->value, 0, 1, ''));
    expect(fn() => $session->receive(new Frame(FrameType::DATA->value, 0, 3, 'x')))
        ->toThrow(RuntimeException::class, 'CONTINUATION');
    $session->receive(new Frame(FrameType::CONTINUATION->value, 0x4, 1, (new Encoder())->encode([[':status', '204']])));
    $session->receive(FrameWriter::goAway(1, ErrorCode::NO_ERROR));
    expect($failed)->toHaveCount(1)
        ->and($failed[0])->toBeInstanceOf(RuntimeException::class);
    expect(fn() => $request('https://example.test/three'))
        ->toThrow(RuntimeException::class, 'admission');
    $session->receive(new Frame(FrameType::DATA->value, 0x1, 1));
    expect($session->activeCount())->toBe(0);
});

it('bounds H2 stream admission before state allocation', function (): void {
    expect(fn() => new Http2ClientMultiplexSession(0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new Http2ClientMultiplexSession(33))->toThrow(InvalidArgumentException::class);
});

it('honors an independent peer SETTINGS concurrency limit across sequential streams', function (): void {
    $session = new Http2ClientMultiplexSession(4);
    $session->start();
    $session->receive(FrameWriter::settings([\Infocyph\Runwire\Http\Http2\PeerSettings::MAX_CONCURRENT_STREAMS => 1]));
    $request = static function () use ($session): array {
        return $session->request(
            new ClientRequest('GET', 'https://example.test/limit'),
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );
    };
    expect($request()['stream'])->toBe(1);
    expect(fn() => $request())->toThrow(RuntimeException::class, 'admission');
    $session->receive(new Frame(
        FrameType::HEADERS->value,
        0x5,
        1,
        (new Encoder())->encode([[':status', '204']]),
    ));
    expect($session->activeCount())->toBe(0)
        ->and($request()['stream'])->toBe(3);
});
