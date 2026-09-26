<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

it('round-trips every QUIC variable integer width', function (int $value): void {
    $wire = VarIntCodec::encode($value);
    $offset = 0;
    expect(VarIntCodec::decode($wire, $offset))->toBe($value)->and($offset)->toBe(strlen($wire));
})->with([0, 63, 64, 16_383, 16_384, 1_073_741_823, 1_073_741_824, VarIntCodec::MAX_VALUE]);

it('parses HTTP/3 frames incrementally across arbitrary stream splits', function (): void {
    $wire = (new Frame(FrameType::HEADERS->value, 'header-block'))->encode() . (new Frame(FrameType::DATA->value, 'body'))->encode();
    $parser = new FrameParser();
    $frames = [];
    foreach (str_split($wire, 2) as $chunk) { array_push($frames, ...$parser->push($chunk)); }
    expect($frames)->toHaveCount(2)->and($frames[0]->knownType())->toBe(FrameType::HEADERS)->and($frames[0]->payload)->toBe('header-block')->and($frames[1]->knownType())->toBe(FrameType::DATA)->and($frames[1]->payload)->toBe('body')->and($parser->bufferedBytes())->toBe(0);
});
