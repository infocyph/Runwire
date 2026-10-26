<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\Internal\Http3ClientResponseStream;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;

it('streams a fragmented H3 response and finishes exactly once', function (): void {
    $headers = [];
    $body = '';
    $stream = new Http3ClientResponseStream(4,
        static function (int $status, \Infocyph\Runwire\Http\Headers $fields) use (&$headers): void {
            $headers[] = [$status, $fields->first('content-length')];
        },
        static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        },
    );
    $block = new Encoder(0, 0)->encode([
        [':status', '200'],
        ['content-length', '5'],
    ], 4)->block;
    $wire = new Frame(FrameType::HEADERS->value, $block)->encode()
        . new Frame(FrameType::DATA->value, 'hello')->encode();
    foreach (str_split($wire) as $part) {
        $stream->push($part);
    }
    $stream->finish();
    expect($headers)->toBe([[200, '5']])
        ->and($body)->toBe('hello')
        ->and(fn() => $stream->finish())->toThrow(Http3Exception::class, 'ended without complete');
});

it('rejects DATA before HEADERS and partial frames at FIN', function (): void {
    $stream = new Http3ClientResponseStream(4, static function (): void {}, static function (): void {});
    expect(fn() => $stream->push(new Frame(FrameType::DATA->value, 'oops')->encode()))
        ->toThrow(Http3Exception::class, 'must start');
    $truncated = new Http3ClientResponseStream(4, static function (): void {}, static function (): void {});
    $truncated->push("\x01\x80");
    expect(fn() => $truncated->finish())->toThrow(Http3Exception::class, 'ended without complete');
});

it('rejects conflicting or incorrect response Content-Length', function (): void {
    $codec = new Encoder(0, 0);
    $bad = new Http3ClientResponseStream(4, static function (): void {}, static function (): void {});
    $duplicate = $codec->encode([
        [':status', '200'], ['content-length', '5'], ['content-length', '6'],
    ], 4)->block;
    expect(fn() => $bad->push(new Frame(FrameType::HEADERS->value, $duplicate)->encode()))
        ->toThrow(Http3Exception::class, 'Conflicting');

    $missing = new Http3ClientResponseStream(4, static function (): void {}, static function (): void {});
    $valid = $codec->encode([[':status', '200'], ['content-length', '3']], 4)->block;
    $missing->push(new Frame(FrameType::HEADERS->value, $valid)->encode());
    expect(fn() => $missing->finish())->toThrow(Http3Exception::class, 'Content-Length mismatch');
});

it('enforces response byte budgets and rejects forbidden frames', function (): void {
    $codec = new Encoder(0, 0);
    $block = $codec->encode([[':status', '200']], 4)->block;
    $stream = new Http3ClientResponseStream(4, static function (): void {}, static function (): void {}, 4);
    $stream->push(new Frame(FrameType::HEADERS->value, $block)->encode());
    expect(fn() => $stream->push(new Frame(FrameType::DATA->value, 'too long')->encode()))
        ->toThrow(Http3Exception::class, 'exceeds configured limit');
    $illegal = new Http3ClientResponseStream(8, static function (): void {}, static function (): void {});
    $illegal->push(new Frame(FrameType::HEADERS->value, $block)->encode());
    expect(fn() => $illegal->push(new Frame(FrameType::SETTINGS->value)->encode()))
        ->toThrow(Http3Exception::class, 'Forbidden');
});


it('emits bounded static-QPACK response trailers and rejects DATA after trailers', function (): void {
    $encoder = new Encoder(0, 0);
    $seen = [];
    $body = '';
    $trailers = [];
    $stream = new Http3ClientResponseStream(
        4,
        static function (int $status) use (&$seen): void { $seen[] = $status; },
        static function (string $bytes) use (&$body): void { $body .= $bytes; },
        onTrailers: static function (\Infocyph\Runwire\Http\Headers $fields) use (&$trailers): void {
            $trailers[] = $fields->first('x-server-check');
        },
    );
    $headers = new Frame(FrameType::HEADERS->value, $encoder->encode([
        [':status', '200'], ['content-length', '2'],
    ], 4)->block);
    $last = new Frame(FrameType::HEADERS->value, $encoder->encode([
        ['x-server-check', 'verified'],
    ], 4)->block);
    foreach (str_split($headers->encode() . new Frame(FrameType::DATA->value, 'ok')->encode() . $last->encode()) as $byte) {
        $stream->push($byte);
    }
    $stream->finish();
    expect($seen)->toBe([200])->and($body)->toBe('ok')->and($trailers)->toBe(['verified']);

    $invalid = new Http3ClientResponseStream(8, static function (): void {}, static function (): void {});
    $invalid->push($headers->encode() . $last->encode());
    expect(fn() => $invalid->push(new Frame(FrameType::DATA->value, 'oops')->encode()))
        ->toThrow(Http3Exception::class, 'after response trailers')
        ->and(fn() => $invalid->push($last->encode()))
        ->toThrow(Http3Exception::class, 'Duplicate');
});

it('rejects HTTP/3 response trailers with pseudo, routing and length fields', function (): void {
    $encoder = new Encoder(0, 0);
    $initial = new Frame(FrameType::HEADERS->value, $encoder->encode([[':status', '200']], 4)->block);
    foreach ([
        [[':status', '201']],
        [['content-length', '0']],
        [['connection', 'close']],
    ] as $fields) {
        $stream = new Http3ClientResponseStream(4, static function (): void {}, static function (): void {});
        $stream->push($initial->encode());
        $trailer = new Frame(FrameType::HEADERS->value, $encoder->encode($fields, 4)->block);
        expect(fn() => $stream->push($trailer->encode()))->toThrow(Http3Exception::class);
    }
});

it('shares the connection-owned QPACK decoder with the peer instruction stream', function (): void {
    $codec = new \Infocyph\Runwire\Http\Client\Internal\Http3ClientWireCodec();
    $codec->receivePeerEncoderInstructions("\\x20");
    $seen = [];
    $stream = new Http3ClientResponseStream(
        4,
        static function (int $status) use (&$seen): void { $seen[] = $status; },
        static function (): void {},
        codec: $codec,
    );
    $block = new Encoder(0, 0)->encode([[':status', '200']], 4)->block;
    $stream->push(new Frame(FrameType::HEADERS->value, $block)->encode());
    $stream->finish();
    expect($seen)->toBe([200]);
    $owner = new ReflectionProperty($stream, 'codec');
    expect($owner->getValue($stream))->toBe($codec);
});

it('retains blocked dynamic QPACK headers and replays queued DATA only after matching encoder inserts', function (): void {
    $codec = new \Infocyph\Runwire\Http\Client\Internal\Http3ClientWireCodec(
        decoderCapacity: 256,
        maxBlocked: 2,
    );
    $encoder = new Encoder(256, 2, dynamicTableCapacity: 256);
    $codec->receivePeerEncoderInstructions($encoder->takeEncoderInstructions());
    $block = $encoder->encode([[':status', '200'], ['x-dynamic', 'peer-value']], 4);
    expect($block->blocking)->toBeTrue();
    $instructions = $encoder->takeEncoderInstructions();
    $headers = [];
    $body = '';
    $stream = new Http3ClientResponseStream(
        4,
        static function (int $status) use (&$headers): void { $headers[] = $status; },
        static function (string $part) use (&$body): void { $body .= $part; },
        codec: $codec,
    );
    $stream->push(new Frame(FrameType::HEADERS->value, $block->block)->encode()
        . new Frame(FrameType::DATA->value, 'sibling-safe')->encode());
    $stream->finish();
    expect($headers)->toBe([])->and($body)->toBe('')->and($stream->isFinished())->toBeFalse();
    $ready = $codec->receivePeerEncoderInstructions($instructions);
    expect($ready)->toHaveCount(1)->and($ready[0]->streamId)->toBe(4);
    $stream->resumeQpack($ready[0]);
    expect($headers)->toBe([200])
        ->and($body)->toBe('sibling-safe')
        ->and($stream->isFinished())->toBeTrue()
        ->and($codec->takeDecoderInstructions())->not->toBe('');
});

it('isolates QPACK-blocked sibling stream sections until each required insertion arrives', function (): void {
    $codec = new \Infocyph\Runwire\Http\Client\Internal\Http3ClientWireCodec(
        decoderCapacity: 512,
        maxBlocked: 2,
    );
    $encoder = new Encoder(512, 2, dynamicTableCapacity: 512);
    $codec->receivePeerEncoderInstructions($encoder->takeEncoderInstructions());
    $first = $encoder->encode([[':status', '200'], ['x-first', 'one']], 4);
    $firstInstructions = $encoder->takeEncoderInstructions();
    $second = $encoder->encode([[':status', '201'], ['x-second', 'two']], 8);
    $secondInstructions = $encoder->takeEncoderInstructions();
    expect($first->blocking)->toBeTrue()->and($second->blocking)->toBeTrue();
    $seen = [];
    $one = new Http3ClientResponseStream(4, static function (int $status) use (&$seen): void {
        $seen[] = 'one-' . $status;
    }, static function (): void {}, codec: $codec);
    $two = new Http3ClientResponseStream(8, static function (int $status) use (&$seen): void {
        $seen[] = 'two-' . $status;
    }, static function (): void {}, codec: $codec);
    $one->push(new Frame(FrameType::HEADERS->value, $first->block)->encode());
    $two->push(new Frame(FrameType::HEADERS->value, $second->block)->encode());
    $one->finish();
    $two->finish();
    expect($seen)->toBe([]);
    $readyFirst = $codec->receivePeerEncoderInstructions($firstInstructions);
    expect($readyFirst)->toHaveCount(1)->and($readyFirst[0]->streamId)->toBe(4);
    $one->resumeQpack($readyFirst[0]);
    expect($seen)->toBe(['one-200'])->and($one->isFinished())->toBeTrue()->and($two->isFinished())->toBeFalse();
    $readySecond = $codec->receivePeerEncoderInstructions($secondInstructions);
    expect($readySecond)->toHaveCount(1)->and($readySecond[0]->streamId)->toBe(8);
    $two->resumeQpack($readySecond[0]);
    expect($seen)->toBe(['one-200', 'two-201'])->and($two->isFinished())->toBeTrue();
});
