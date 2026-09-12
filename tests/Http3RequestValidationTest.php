<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameWriter;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Internal\RequestStream;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;

it('maps malformed HTTP/3 pseudo-header combinations to H3_MESSAGE_ERROR', function (): void {
    $encoder = new Encoder(0, 0);
    $stream = new RequestStream(0, new Decoder(0, 0), new Http3Limits());
    $block = $encoder->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', ''],
        [':path', '/'],
    ], 0)->block;

    try {
        $stream->push(FrameWriter::encode(new Frame(FrameType::HEADERS->value, $block)));
        test()->fail('Malformed HTTP/3 request pseudo-headers should fail.');
    } catch (Http3Exception $exception) {
        expect($exception->errorCode)->toBe(ErrorCode::MESSAGE_ERROR);
    }
});
