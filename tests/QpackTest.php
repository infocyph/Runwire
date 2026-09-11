<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Http3\Qpack\DynamicTable;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;
use Infocyph\Runwire\Http\Http3\Qpack\EncoderStreamDecoder;
use Infocyph\Runwire\Http\Http3\Qpack\FieldSectionDecoder;

it('decodes the RFC 9204 static-name literal example', function (): void {
    $decoder = new FieldSectionDecoder(new DynamicTable(0));
    $section = $decoder->decode(hex2bin('0000510b2f696e6465782e68746d6c'));
    expect($section->fields)->toBe([[':path', '/index.html']])->and($section->requiredInsertCount)->toBe(0)->and($section->dynamicReferenced)->toBeFalse();
});

it('decodes RFC 9204 dynamic table inserts and post-base references', function (): void {
    $table = new DynamicTable(220);
    $encoderStream = new EncoderStreamDecoder($table);
    $insertions = $encoderStream->push(hex2bin('3fbd01c00f7777772e6578616d706c652e636f6dc10c2f73616d706c652f70617468'));
    $section = (new FieldSectionDecoder($table))->decode(hex2bin('03811011'));
    expect($insertions)->toBe(2)->and($table->insertCount())->toBe(2)->and($section->fields)->toBe([[':authority', 'www.example.com'], [':path', '/sample/path']])->and($section->requiredInsertCount)->toBe(2)->and($section->dynamicReferenced)->toBeTrue();
});

it('blocks and later releases a field section when encoder inserts arrive', function (): void {
    $encoder = new Encoder(220, 1, dynamicTableCapacity: 220);
    $decoder = new Decoder(220, 1);
    $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());
    $encoded = $encoder->encode([[':authority', 'www.example.com'], [':path', '/sample/path']], 4);
    $encoderInstructions = $encoder->takeEncoderInstructions();
    expect($encoded->requiredInsertCount)->toBeGreaterThan(0)->and($decoder->decode($encoded->block, 4))->toBeNull();
    $ready = $decoder->pushEncoderInstructions($encoderInstructions);
    expect($ready)->toHaveCount(1)->and($ready[0]->streamId)->toBe(4)->and($ready[0]->section->fields)->toBe([[':authority', 'www.example.com'], [':path', '/sample/path']]);
    $encoder->pushDecoderInstructions($decoder->takeDecoderInstructions());
    expect($encoder->knownReceivedCount())->toBeGreaterThanOrEqual($encoded->requiredInsertCount);
});
