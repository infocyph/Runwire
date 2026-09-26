<?php

declare(strict_types=1);

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\SocketCapabilityProbe;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\ApplicationLifecycle;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Internal\SystemResourceProbe;
use Infocyph\Runwire\Runtime\RuntimeCapabilityResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\SystemResources;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\Supervisor\PrivilegeDropPolicy;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

function batchFRequest(string $target, ProtocolVersion $version = ProtocolVersion::HTTP_1_1): HttpRequest
{
    return new HttpRequest(
        method: 'GET',
        target: $target,
        version: $version,
        headers: new Headers(),
        body: new BufferedRequestBody(''),
    );
}

/** @param ArrayObject<string, mixed> $state */
function batchFWriter(ArrayObject $state): ResponseWriterInterface
{
    return new CallbackResponseWriter(
        static function (int $status, Headers $headers) use ($state): void {
            $state['status'] = $status;
            $state['headers'] = $headers;
        },
        static function (string $chunk) use ($state): void {
            $state['body'] = ($state['body'] ?? '') . $chunk;
        },
        static function () use ($state): void {
            $state['ended'] = true;
        },
        1_024,
    );
}

it('rejects overlapping HTTP requests without crashing the worker lifecycle', function (): void {
    $runtime = RuntimeContext::standalone();
    $overload = new ArrayObject();
    $lifecycle = null;
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$lifecycle, $overload): void {
            if ($request->target === '/first') {
                if (!$lifecycle instanceof ApplicationLifecycle) {
                    throw new LogicException('Lifecycle test fixture was not initialized.');
                }
                $lifecycle->handle(batchFRequest('/overload'), batchFWriter($overload));
            }
            $writer->end();
        },
        $runtime,
        admission: new AdmissionPolicy(maxActiveRequests: 1, retryAfterSeconds: 2),
    );

    $primary = new ArrayObject();
    $lifecycle->handle(batchFRequest('/first'), batchFWriter($primary));

    $headers = $overload['headers'] ?? null;
    expect($overload['status'] ?? null)->toBe(503)
        ->and($overload['ended'] ?? false)->toBeTrue()
        ->and($headers)->toBeInstanceOf(Headers::class)
        ->and($headers->first('retry-after'))->toBe('2')
        ->and($headers->first('connection'))->toBe('close')
        ->and($runtime->snapshot()->rejectedRequestsTotal)->toBe(1);
});

it('keeps HTTP3 overload rejection request-local', function (): void {
    $runtime = RuntimeContext::standalone();
    $overload = new ArrayObject();
    $lifecycle = null;
    $lifecycle = new ApplicationLifecycle(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$lifecycle, $overload): void {
            if ($request->target === '/first') {
                if (!$lifecycle instanceof ApplicationLifecycle) {
                    throw new LogicException('Lifecycle test fixture was not initialized.');
                }
                $lifecycle->handle(
                    batchFRequest('/overload', ProtocolVersion::HTTP_3),
                    batchFWriter($overload),
                );
            }
            $writer->end();
        },
        $runtime,
        admission: new AdmissionPolicy(maxStreamsPerWorker: 1),
    );

    $lifecycle->handle(
        batchFRequest('/first', ProtocolVersion::HTTP_3),
        batchFWriter(new ArrayObject()),
    );

    $headers = $overload['headers'] ?? null;
    expect($overload['status'] ?? null)->toBe(503)
        ->and($headers)->toBeInstanceOf(Headers::class)
        ->and($headers->has('connection'))->toBeFalse()
        ->and($runtime->snapshot()->rejectedRequestsTotal)->toBe(1);
});

it('resolves cgroup constrained resources and preserves explicit worker counts', function (): void {
    $files = [
        '/sys/fs/cgroup/cpu.max' => '150000 100000',
        '/sys/fs/cgroup/cpuset.cpus.effective' => '0-3',
        '/sys/fs/cgroup/memory.max' => (string) (512 * 1_024 * 1_024),
    ];
    $probe = new SystemResourceProbe(
        static fn(string $path): ?string => $files[$path] ?? null,
        hostCpuCount: 8,
        hostMemoryBytes: 8 * 1_024 * 1_024 * 1_024,
    );
    $resources = $probe->probe();

    expect($resources->effectiveCpuCount)->toBe(1)
        ->and($resources->effectiveMemoryBytes)->toBe(512 * 1_024 * 1_024)
        ->and($resources->resolveWorkerCount(0))->toBe(1)
        ->and($resources->resolveWorkerCount(6))->toBe(6);
});

it('allows native server definitions to opt into automatic worker sizing', function (): void {
    $server = new Server(
        'web',
        '127.0.0.1:0',
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {},
        workers: 0,
    );
    $datagram = new DatagramServer(
        'udp',
        '127.0.0.1:0',
        static function (Datagram $datagram, DatagramListener $listener): void {},
        workers: 0,
    );

    expect($server->workers)->toBe(0)
        ->and($datagram->workers)->toBe(0);
});

it('keeps admission and identity policy independently configurable per worker group', function (): void {
    $first = WorkerGroup::callbacks(
        'first',
        1,
        static function (WorkerContext $context): void {},
        admissionPolicy: new AdmissionPolicy(maxActiveRequests: 2),
    );
    $second = WorkerGroup::callbacks(
        'second',
        1,
        static function (WorkerContext $context): void {},
        admissionPolicy: new AdmissionPolicy(maxActiveRequests: 7),
    );

    expect($first->admissionPolicy->maxActiveRequests)->toBe(2)
        ->and($second->admissionPolicy->maxActiveRequests)->toBe(7)
        ->and($first->privilegeDropPolicy->enabled())->toBeFalse();
});

it('preflights a no-op worker identity policy before serving', function (): void {
    $policy = new PrivilegeDropPolicy(uid: posix_geteuid(), gid: posix_getegid());

    $policy->assertSupported();
    $policy->apply();

    expect($policy->enabled())->toBeTrue()
        ->and(posix_geteuid())->toBe($policy->uid)
        ->and(posix_getegid())->toBe($policy->gid);
});

it('reports native resource and socket capabilities explicitly', function (): void {
    $resources = new SystemResources(2, 1_073_741_824);
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        supportsFork: true,
        supportsSignals: true,
        supportsPosix: true,
        supportsReusePort: true,
        supportsUnixSockets: true,
        supportsPrivilegeDrop: true,
        resources: $resources,
    );
    $capabilities = (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::NATIVE, $environment);

    expect($capabilities)->toBeInstanceOf(RuntimeCapabilities::class)
        ->and($capabilities->toArray())->toMatchArray([
            'supports_fork' => true,
            'supports_signals' => true,
            'supports_reuse_port' => true,
            'supports_unix_sockets' => true,
            'supports_privilege_drop' => true,
            'effective_cpu_count' => 2,
            'effective_memory_bytes' => 1_073_741_824,
        ]);
});

it('keeps reuse port default-off and forwards it to HTTP3 only when explicit', function (): void {
    $listener = new ListenerOptions();
    expect($listener->reusePort)->toBeFalse();

    $certificate = tempnam(sys_get_temp_dir(), 'runwire-cert-');
    if (!is_string($certificate)) {
        throw new RuntimeException('Unable to create temporary certificate fixture.');
    }

    try {
        file_put_contents($certificate, 'fixture');
        $tls = new TlsOptions($certificate);
        $http3 = new Http3Options();
        expect($http3->listenerOptions($tls)['reuse_port'])->toBeFalse()
            ->and($http3->listenerOptions($tls, true)['reuse_port'])->toBeTrue();
    } finally {
        if (is_file($certificate)) {
            unlink($certificate);
        }
    }

    expect(SocketCapabilityProbe::supportsReusePort())->toBeBool();
});


it('uses bounded worker request and multiplexed stream admission defaults', function (): void {
    $policy = new AdmissionPolicy();

    expect($policy->maxActiveRequests)->toBe(256)
        ->and($policy->maxStreamsPerWorker)->toBe(256)
        ->and($policy->enabled())->toBeTrue();
});
