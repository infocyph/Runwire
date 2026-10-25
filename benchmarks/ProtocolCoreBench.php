<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Internal\RequestHeadValidator;
use Infocyph\Runwire\Http\Http2\FrameParser as Http2FrameParser;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder as HpackDecoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder as HpackEncoder;
use Infocyph\Runwire\Http\Http2\Hpack\HuffmanCodec;
use Infocyph\Runwire\Http\Http2\Hpack\IntegerCodec as HpackIntegerCodec;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode as Http3ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder as QpackDecoder;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder as QpackEncoder;
use Infocyph\Runwire\Http\Http3\Qpack\IntegerCodec as QpackIntegerCodec;
use Infocyph\Runwire\Http\Http3\VarIntCodec;
use Infocyph\Runwire\Http\Internal\RequestHeaderValidator;
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

    private string $http2FrameWire;

    /** @var list<array{0: string, 1: string}> */
    private array $http2Headers;

    private Frame $http3Frame;

    /** @var list<array{0: string, 1: string}> */
    private array $http3Headers;

    private string $http3Wire;

    private HuffmanCodec $huffmanCodec;

    private string $huffmanWire;

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
        $this->huffmanCodec = new HuffmanCodec();
        $this->huffmanWire = $this->huffmanCodec->encode('https://example.test/benchmark');
        $this->http2FrameWire = "\x00\x04\x00\x00\x00" . pack('N', 1) . str_repeat('x', 1_024);

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
        $this->http3Wire = $this->http3Frame->encode();
        $this->qpackEncoder = new QpackEncoder(0, 0);
        $this->qpackDecoder = new QpackDecoder(0, 0);
        $this->qpackWire = (new QpackEncoder(0, 0))->encode($this->http3Headers, 0)->block;
    }

    public function benchHttp1HeadValidation(): int
    {
        return $this->http1Validator->validate(
            $this->http1Headers,
            16_777_216,
            'POST',
            '/benchmark?transport=h1',
        )->contentLength;
    }

    public function benchHttp2FragmentedFrameParse(): int
    {
        $parser = new Http2FrameParser();
        $parser->push(substr($this->http2FrameWire, 0, 7));
        $frames = $parser->push(substr($this->http2FrameWire, 7));

        return strlen($frames[0]->payload);
    }

    public function benchHttp2FrameParse(): int
    {
        $frames = (new Http2FrameParser())->push($this->http2FrameWire);

        return strlen($frames[0]->payload);
    }

    public function benchHttp2HpackDecode(): int
    {
        return count($this->hpackDecoder->decode($this->hpackWire));
    }

    public function benchHttp2HpackEncode(): int
    {
        return strlen($this->hpackEncoder->encode($this->http2Headers));
    }

    public function benchHttp2HpackIntegerRoundTrip(): int
    {
        $encoded = HpackIntegerCodec::encode(16_384, 5);
        $offset = 0;

        return HpackIntegerCodec::decode($encoded, $offset, 5);
    }

    public function benchHttp2HuffmanDecode(): int
    {
        return strlen($this->huffmanCodec->decode($this->huffmanWire, 256));
    }

    public function benchHttp2HuffmanEncode(): int
    {
        return strlen($this->huffmanCodec->encode('https://example.test/benchmark'));
    }

    public function benchHttp2RequestHeaderValidation(): int
    {
        $head = (new RequestHeaderValidator('HTTP/2'))->request($this->http2Headers);

        return strlen($head->target);
    }

    public function benchHttp2HpackDynamicTableChurn(): int
    {
        $encoder = new HpackEncoder(512);
        $decoder = new HpackDecoder(512);
        $validated = 0;

        for ($index = 0; $index < 12; ++$index) {
            $headers = [
                [':method', 'GET'],
                [':path', '/churn/' . ($index % 5)],
                ['x-runwire-rotation', 'value-' . ($index % 7)],
                ['authorization', 'never-index-' . $index],
            ];
            if ($decoder->decode($encoder->encode($headers)) !== $headers) {
                throw new RuntimeException('HPACK dynamic churn failed header fidelity.');
            }
            ++$validated;
        }

        return $validated;
    }

    public function benchHttp2HpackLongContinuationInteger(): int
    {
        $encoded = HpackIntegerCodec::encode(PHP_INT_MAX, 5);
        $offset = 0;
        $decoded = HpackIntegerCodec::decode($encoded, $offset, 5);
        if ($decoded !== PHP_INT_MAX || $offset !== strlen($encoded)) {
            throw new RuntimeException('HPACK long integer continuation mismatch.');
        }

        return $decoded;
    }

    public function benchHttp2HuffmanLargeBoundedDecode(): int
    {
        $value = str_repeat('runwire-hpack-pressure/', 12);
        $wire = $this->huffmanCodec->encode($value);
        if ($this->huffmanCodec->decode($wire, strlen($value)) !== $value) {
            throw new RuntimeException('HPACK bounded Huffman decode mismatch.');
        }

        return strlen($wire);
    }

    public function benchHttp2HpackRepeatedHeaderValidation(): int
    {
        $headers = [
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'benchmark.example'],
            [':path', '/validation'],
            ['x-validation', 'bounded'],
        ];
        $validated = 0;
        for ($index = 0; $index < 8; ++$index) {
            $request = (new RequestHeaderValidator('HTTP/2'))->request($headers);
            $validated += strlen($request->target);
        }

        return $validated;
    }

    public function benchHttp3FrameDecode(): int
    {
        $frames = (new FrameParser())->push($this->http3Wire);

        return strlen($frames[0]->payload);
    }

    public function benchHttp3FrameEncode(): int
    {
        return strlen($this->http3Frame->encode());
    }

    public function benchHttp3QpackBlockedUnblock(): int
    {
        $encoder = new QpackEncoder(512, 8, dynamicTableCapacity: 512);
        $decoder = new QpackDecoder(512, 8, maxBlockedBytes: 8_192);
        $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());

        $fields = [
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.test'],
            [':path', '/benchmark'],
            ['x-runwire-dynamic', 'blocked-profile-value'],
        ];
        $encoded = $encoder->encode($fields, 0);
        if (!$encoded->blocking || $decoder->decode($encoded->block, 0) !== null) {
            throw new RuntimeException('QPACK blocking benchmark did not enter pending state.');
        }

        $unblocked = $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());
        if (count($unblocked) !== 1 || $unblocked[0]->streamId !== 0) {
            throw new RuntimeException('QPACK blocking benchmark did not unblock exactly one stream.');
        }

        $feedback = $decoder->takeDecoderInstructions();
        if ($feedback !== '') {
            $encoder->pushDecoderInstructions($feedback);
        }

        return count($unblocked[0]->section->fields);
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

    public function benchHttp3QpackIntegerRoundTrip(): int
    {
        $encoded = QpackIntegerCodec::encode(16_384, 5);
        $offset = 0;

        return QpackIntegerCodec::decode($encoded, $offset, 5, Http3ErrorCode::QPACK_DECOMPRESSION_FAILED);
    }

    public function benchHttp3QpackStaticDecode(): int
    {
        return count($this->qpackDecoder->decode($this->qpackWire, 0)?->fields ?? []);
    }

    public function benchHttp3QpackStaticEncode(): int
    {
        return strlen($this->qpackEncoder->encode($this->http3Headers, 0)->block);
    }

    public function benchHttp3RequestHeaderValidation(): int
    {
        $head = (new RequestHeaderValidator('HTTP/3'))->request($this->http3Headers);

        return strlen($head->target);
    }

    public function benchHttp3VarIntRoundTrip(): int
    {
        $encoded = VarIntCodec::encode(1_073_741_824);
        $offset = 0;

        return VarIntCodec::decode($encoded, $offset);
    }
}
