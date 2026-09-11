<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Http3ResponseWriter;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Network\WriteState;

function http3Accepted(int $buffered = 0): WriteResult
{
    return new WriteResult(WriteState::ACCEPTED, $buffered);
}

it('writes HTTP/3 response semantics through transport-neutral callbacks', function (): void {
    $headerBlocks = [];
    $dataFrames = [];
    $ended = 0;
    $writer = new Http3ResponseWriter(
        'GET',
        static function (int $status, array $fields) use (&$headerBlocks): WriteResult {
            $headerBlocks[] = [$status, $fields];

            return http3Accepted();
        },
        static function (string $data, bool $fin) use (&$dataFrames): WriteResult {
            $dataFrames[] = [$data, $fin];

            return http3Accepted();
        },
        static function (): void {},
        static function () use (&$ended): void {
            ++$ended;
        },
    );

    expect($writer->start(201, Headers::fromArray(['content-length' => '5', 'x-test' => 'yes']))->accepted())->toBeTrue()
        ->and($writer->write('he')->accepted())->toBeTrue()
        ->and($writer->end('llo')->accepted())->toBeTrue()
        ->and($writer->isEnded())->toBeTrue()
        ->and($headerBlocks)->toBe([[
            201,
            [
                [':status', '201'],
                ['content-length', '5'],
                ['x-test', 'yes'],
            ],
        ]])
        ->and($dataFrames)->toBe([['he', false], ['llo', true]])
        ->and($ended)->toBe(1);
});

it('suppresses HTTP/3 bodies where HTTP semantics forbid them', function (string $method, int $status): void {
    $dataFrames = [];
    $writer = new Http3ResponseWriter(
        $method,
        static fn(): WriteResult => http3Accepted(),
        static function (string $data, bool $fin) use (&$dataFrames): WriteResult {
            $dataFrames[] = [$data, $fin];

            return http3Accepted();
        },
        static function (): void {},
        static function (): void {},
    );

    $headers = $status === 204 ? new Headers() : Headers::fromArray(['content-length' => '4']);
    $writer->start($status, $headers);
    $writer->end($status === 204 ? '' : 'body');

    expect($dataFrames)->toBe([['', true]])
        ->and($writer->isEnded())->toBeTrue();
})->with([
    ['HEAD', 200],
    ['GET', 204],
    ['GET', 304],
]);

it('preserves pressure and drain signaling without transport coupling', function (): void {
    $drain = null;
    $calls = 0;
    $writer = new Http3ResponseWriter(
        'GET',
        static fn(): WriteResult => http3Accepted(),
        static fn(): WriteResult => new WriteResult(WriteState::PRESSURED, 128),
        static function (Closure $callback) use (&$drain): void {
            $drain = $callback;
        },
        static function (): void {},
    );

    $writer->onDrain(static function () use (&$calls): void {
        ++$calls;
    });
    $result = $writer->write('chunk');
    $drain?->__invoke();

    expect($result->state)->toBe(WriteState::PRESSURED)
        ->and($result->bufferedBytes)->toBe(128)
        ->and($calls)->toBe(1);
});

it('rejects invalid HTTP/3 response framing metadata before transport writes', function (): void {
    $writer = new Http3ResponseWriter(
        'GET',
        static fn(): WriteResult => http3Accepted(),
        static fn(): WriteResult => http3Accepted(),
        static function (): void {},
        static function (): void {},
    );

    expect(fn() => $writer->start(200, new Headers([new HeaderField('connection', 'close')])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $writer->start(200, new Headers([
            new HeaderField('content-length', '1'),
            new HeaderField('content-length', '2'),
        ])))
        ->toThrow(InvalidArgumentException::class);
});
