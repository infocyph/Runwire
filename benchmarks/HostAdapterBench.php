<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Host\HostRequestFactory;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Revs(1_000)]
#[Warmup(1)]
final class HostAdapterBench
{
    private RuntimeApplication $application;

    private string $body;

    private HostRequestFactory $factory;

    private HttpRequest $request;

    /** @var array<string, mixed> */
    private array $server;

    private ResponseWriterInterface $writer;

    public function setUp(): void
    {
        $this->factory = new HostRequestFactory();
        $this->body = '{"runwire":"benchmark","ok":true}';
        $this->server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/benchmark?driver=host',
            'SERVER_PROTOCOL' => 'HTTP/2.0',
            'HTTP_HOST' => 'example.test',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => 'runwire-benchmark/1',
            'HTTP_X_TRACE_ID' => 'benchmark-trace',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($this->body),
            'REMOTE_ADDR' => '127.0.0.1',
            'REMOTE_PORT' => '41234',
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_PORT' => '443',
            'HTTPS' => 'on',
        ];
        $this->request = $this->factory->fromServer($this->server, $this->body, 1_024);
        $this->writer = $this->newWriter();
        $this->application = new RuntimeApplication(
            static function (HttpRequest $request, ResponseWriterInterface $writer): void {
                $request->headers->first('x-trace-id');
                $writer->isStarted();
            },
            static function (): void {},
        );
    }

    public function benchHostRequestNormalization(): int
    {
        $request = $this->factory->fromServer($this->server, $this->body, 1_024);

        return strlen($request->target);
    }

    public function benchApplicationDispatchAndCleanup(): int
    {
        $this->application->handle($this->request, $this->writer);

        return strlen($this->request->method);
    }

    public function benchHostResponseWriterLifecycle(): int
    {
        $writer = $this->newWriter();
        $result = $writer->end('runwire');

        return $result->bufferedBytes;
    }

    private function newWriter(): ResponseWriterInterface
    {
        return new CallbackResponseWriter(
            static function (): void {},
            static function (string $chunk): void {
                strlen($chunk);
            },
            static function (): void {},
            1_024,
        );
    }
}
