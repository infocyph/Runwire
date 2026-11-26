<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Internal\RequestHeadValidator;
use Infocyph\Runwire\Http\Http2\FrameParser as Http2FrameParser;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder as HpackDecoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder as HpackEncoder;
use Infocyph\Runwire\Http\Http2\Hpack\HuffmanCodec;
use Infocyph\Runwire\Http\Http2\Hpack\IntegerCodec as HpackIntegerCodec;
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

    private HuffmanCodec $huffmanCodec;

    private string $huffmanWire;

    /**
     * Initialize fixtures before PHPBench's per-iteration setup refresh.
     * @throws \Throwable
     */
    public function __construct()
    {
        $this->setUp();
    }

    /**
     * @throws \Throwable
     */
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

    }

    /**
     * @throws \Infocyph\Runwire\Http\Http1\Internal\ParseFailure
     */
    public function benchHttp1HeadValidation(): int
    {
        return $this->http1Validator->validate(
            $this->http1Headers,
            16_777_216,
            'POST',
            '/benchmark?transport=h1',
        )->contentLength;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \OverflowException
     */
    public function benchHttp2FragmentedFrameParse(): int
    {
        $parser = new Http2FrameParser();
        $parser->push(substr($this->http2FrameWire, 0, 7));
        $frames = $parser->push(substr($this->http2FrameWire, 7));

        return strlen($frames[0]->payload);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \OverflowException
     */
    public function benchHttp2FrameParse(): int
    {
        $frames = (new Http2FrameParser())->push($this->http2FrameWire);

        return strlen($frames[0]->payload);
    }

    /**
     * @throws \Infocyph\Runwire\Http\Http2\Hpack\HpackException
     * @throws \InvalidArgumentException
     */
    public function benchHttp2HpackDecode(): int
    {
        return count($this->hpackDecoder->decode($this->hpackWire));
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
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

    /**
     * @throws \InvalidArgumentException
     */
    public function benchHttp2HpackEncode(): int
    {
        return strlen($this->hpackEncoder->encode($this->http2Headers));
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \Infocyph\Runwire\Http\Http2\Hpack\HpackException
     */
    public function benchHttp2HpackIntegerRoundTrip(): int
    {
        $encoded = HpackIntegerCodec::encode(16_384, 5);
        $offset = 0;

        return HpackIntegerCodec::decode($encoded, $offset, 5);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
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

    /**
     * @throws \InvalidArgumentException
     * @throws \Infocyph\Runwire\Http\Http2\Hpack\HpackException
     */
    public function benchHttp2HuffmanDecode(): int
    {
        return strlen($this->huffmanCodec->decode($this->huffmanWire, 256));
    }

    public function benchHttp2HuffmanEncode(): int
    {
        return strlen($this->huffmanCodec->encode('https://example.test/benchmark'));
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function benchHttp2HuffmanLargeBoundedDecode(): int
    {
        $value = str_repeat('runwire-hpack-pressure/', 12);
        $wire = $this->huffmanCodec->encode($value);
        if ($this->huffmanCodec->decode($wire, strlen($value)) !== $value) {
            throw new RuntimeException('HPACK bounded Huffman decode mismatch.');
        }

        return strlen($wire);
    }

    /**
     * @throws \Infocyph\Runwire\Http\Internal\HeaderValidationException
     * @throws \InvalidArgumentException
     */
    public function benchHttp2RequestHeaderValidation(): int
    {
        $head = (new RequestHeaderValidator('HTTP/2'))->request($this->http2Headers);

        return strlen($head->target);
    }

    /**
     * @throws \Infocyph\Runwire\Http\Internal\HeaderValidationException
     * @throws \InvalidArgumentException
     */
    public function benchHttp2RequestHeaderValidationChurn(): int
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
}
