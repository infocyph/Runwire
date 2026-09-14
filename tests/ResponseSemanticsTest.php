<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http2\Http2ResponseWriter;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;

it('ends suppressed HTTP/2 responses without matching representation Content-Length', function (string $method, int $status): void {
    $data = [];
    $writer = new Http2ResponseWriter(
        $method,
        static fn(): WriteResult => new WriteResult(WriteState::ACCEPTED, 0),
        static function (string $chunk, bool $end) use (&$data): WriteResult {
            $data[] = [$chunk, $end];

            return new WriteResult(WriteState::ACCEPTED, 0);
        },
        static function (): void {},
        static function (): void {},
    );

    $writer->start($status, Headers::fromArray(['content-length' => '4']));
    $writer->end();

    expect($data)->toBe([['', true]])
        ->and($writer->isEnded())->toBeTrue();
})->with([
    ['HEAD', 200],
    ['GET', 304],
]);

it('suppresses HTTP 205 content and rejects informational final responses', function (): void {
    $chunks = [];
    $writer = new CallbackResponseWriter(
        static function (): void {},
        static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        },
        static function (): void {},
        64,
    );

    $writer->start(205, new Headers([new HeaderField('content-length', '0')]));
    $writer->end('hidden');

    $informational = new CallbackResponseWriter(
        static function (): void {},
        static function (): void {},
        static function (): void {},
        64,
    );

    expect($chunks)->toBe([])
        ->and(fn() => $informational->start(103))->toThrow(InvalidArgumentException::class);
});

it('rejects non-zero Content-Length on HTTP 205 responses', function (): void {
    $writer = new Http2ResponseWriter(
        'GET',
        static fn(): WriteResult => new WriteResult(WriteState::ACCEPTED, 0),
        static fn(): WriteResult => new WriteResult(WriteState::ACCEPTED, 0),
        static function (): void {},
        static function (): void {},
    );

    expect(fn() => $writer->start(205, Headers::fromArray(['content-length' => '1'])))
        ->toThrow(InvalidArgumentException::class);
});
