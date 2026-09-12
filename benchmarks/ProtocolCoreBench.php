<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Internal\RequestHeadValidator;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder as HpackDecoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder as HpackEncoder;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\FrameWriter;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder as QpackDecoder;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder as QpackEncoder;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Revs(1_000)]
#[Warmup(1)]
final class ProtocolCoreBench
{
    private HpackDecoder $hpackDecoder;

    private HpackEncoder $hpackEncoder;

    private string $hpackWire;

    private Headers $http1Headers;

    private RequestHeadValidator $http1Validator;

    /** @var list<array{0: string, 1: string}> */
    private array $http2Headers;

    private Frame $http3Frame;

    /** @var list<array{0: string, 1: string}> */
    private array $http3Headers;

    private string $http3Wire;

    private QpackDecoder $qpackDecoder;

    private QpackEncoder $qpackEncoder;

    private string $qpackWire;

    public function setUp(): void
    {
        $this->http1Validator = new RequestHeadValidator();
        $this->http1Headers = Headers::fromArray([
            'host' => 'example.test',
            'content-length' => '512',
            'content-type' => 'application/json',
            'connection' => 'keep-alive',
            'x-runwire-bench' => 'protocol-core',
        ]);

        $this->http2Headers = [
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.test'],
            [':path', '/benchmark?transport=h2'],
            ['accept', 'application/json'],
            ['user-agent', 'runwire-benchmark/1'],
            ['x-runwire-bench', 'protocol-core'],
        ];
        $this->hpackEncoder = new HpackEncoder(0);
        $this->hpackDecoder = new HpackDecoder(0);
        $this->hpackWire = (new HpackEncoder(0))->encode($this->http2Headers);

        $this->http3Headers = [
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.test'],
            [':path', '/benchmark?transport=h3'],
            ['accept', 'application/json'],
            ['user-agent', 'runwire-benchmark/1'],
            ['x-runwire-bench', 'protocol-core'],
        ];
        $this->http3Frame = new Frame(FrameType::DATA->value, str_repeat('x', 1_024));
        $this->http3Wire = FrameWriter::encode($this->http3Frame);
        $this->qpackEncoder = new QpackEncoder(0, 0);
        $this->qpackDecoder = new QpackDecoder(0, 0);
        $this->qpackWire = (new QpackEncoder(0, 0))->encode($this->http3Headers, 0)->block;
    }

    public function benchHttp1HeadValidation(): int
    {
        return $this->http1Validator->validate($this->http1Headers, 16_777_216)->contentLength;
    }

    public function benchHttp2HpackDecode(): int
    {
        return count($this->hpackDecoder->decode($this->hpackWire));
    }

    public function benchHttp2HpackEncode(): int
    {
        return strlen($this->hpackEncoder->encode($this->http2Headers));
    }

    public function benchHttp3FrameDecode(): int
    {
        $frames = (new FrameParser())->push($this->http3Wire);

        return strlen($frames[0]->payload);
    }

    public function benchHttp3FrameEncode(): int
    {
        return strlen(FrameWriter::encode($this->http3Frame));
    }

    public function benchHttp3QpackDynamicRoundTrip(): int
    {
        $encoder = new QpackEncoder(512, 8, dynamicTableCapacity: 512);
        $decoder = new QpackDecoder(512, 8, maxBlockedBytes: 8_192);
        $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());

        $encoded = $encoder->encode($this->http3Headers, 0);
        $decoded = $decoder->decode($encoded->block, 0);
        $ready = $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());
        if ($decoded === null) {
            $decoded = $ready[0]->section ?? throw new RuntimeException('QPACK benchmark section did not unblock.');
        }

        $instructions = $decoder->takeDecoderInstructions();
        if ($instructions !== '') {
            $encoder->pushDecoderInstructions($instructions);
        }

        return count($decoded->fields);
    }

    public function benchHttp3QpackStaticDecode(): int
    {
        return count($this->qpackDecoder->decode($this->qpackWire, 0)?->fields ?? []);
    }

    public function benchHttp3QpackStaticEncode(): int
    {
        return strlen($this->qpackEncoder->encode($this->http3Headers, 0)->block);
    }
}
