<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientAdmissionGate;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientGoawayState;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientSiblingExchange;

it('drains only refused H3 IDs, preserves admitted predecessors and rejects late opens', function (): void {
    $admission = new Http3ClientAdmissionGate(2, 3);
    $admission->enqueue(1);
    $admission->enqueue(2);
    $admission->enqueue(3);
    expect($admission->start())->toBe([1, 2]);
    $exchanges = [];
    foreach ([1 => 4, 2 => 8] as $id => $wireId) {
        $exchange = new Http3ClientSiblingExchange(
            new ClientRequest('GET', 'https://example.test/' . $id),
            static function (): void {},
            static function (): void {},
            static function (): void {},
            null,
        );
        $exchange->stream = $id;
        $exchange->wireStreamId = $wireId;
        $exchanges[$id] = $exchange;
    }
    $cutoff = new Http3ClientGoawayState();
    $queued = [];
    $refused = [];
    $rejectPending = static function (int $id) use (&$queued): void { $queued[] = $id; };
    $rejectActive = static function (int $id) use (&$refused): void { $refused[] = $id; };
    $cutoff->drain(null, $admission, $exchanges, $rejectPending, $rejectActive);
    expect($queued)->toBe([])
        ->and($refused)->toBe([])
        ->and($admission->pendingCount())->toBe(1);
    $cutoff->drain(8, $admission, $exchanges, $rejectPending, $rejectActive);
    expect($queued)->toBe([3])
        ->and($refused)->toBe([2])
        ->and($cutoff->refuses(4))->toBeFalse()
        ->and($cutoff->refuses(8))->toBeTrue()
        ->and($cutoff->refuses(12))->toBeTrue()
        ->and(fn() => $admission->enqueue(4))->toThrow(RuntimeException::class);
});
