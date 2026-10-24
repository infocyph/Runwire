<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Internal\ConnectionError;
use Infocyph\Runwire\Http\Http2\Internal\ControlFrameBudget;
use Infocyph\Runwire\Loop\SelectLoop;

it('rejects inbound WINDOW_UPDATE floods without legitimate outbound DATA', function (): void {
    $budget = new ControlFrameBudget(new SelectLoop(), 2);
    $budget->consume(FrameType::WINDOW_UPDATE);
    $budget->consume(FrameType::WINDOW_UPDATE);

    expect(fn() => $budget->consume(FrameType::WINDOW_UPDATE))
        ->toThrow(ConnectionError::class);
});

it('credits only bounded WINDOW_UPDATE work earned from queued HTTP2 DATA', function (): void {
    $budget = new ControlFrameBudget(new SelectLoop(), 2);
    $budget->creditOutboundData(16_384);
    $budget->consume(FrameType::WINDOW_UPDATE);
    $budget->consume(FrameType::WINDOW_UPDATE);
    $budget->consume(FrameType::WINDOW_UPDATE);
    $budget->consume(FrameType::WINDOW_UPDATE);

    expect(fn() => $budget->consume(FrameType::WINDOW_UPDATE))
        ->toThrow(ConnectionError::class);
});

it('does not exempt PING or SETTINGS floods using outbound DATA credits', function (): void {
    $budget = new ControlFrameBudget(new SelectLoop(), 2);
    $budget->creditOutboundData(65_535);
    $budget->consume(FrameType::PING);
    $budget->consume(FrameType::SETTINGS);

    expect(fn() => $budget->consume(FrameType::PING))
        ->toThrow(ConnectionError::class);
});
