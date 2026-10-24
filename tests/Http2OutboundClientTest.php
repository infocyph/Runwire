<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http2OutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\Network\TlsOptions;

it('rejects H2 plaintext, origin substitution and disallowed ALPN before socket admission', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $dialer = new Dialer($loop);
    $race = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $headers = static function (): void {};
    $body = static function (): void {};
    $complete = static function (): void {};
    try {
        $plaintext = new ClientRequest(
            'GET',
            'http://example.test/',
            protocol: new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true),
        );
        expect(fn() => Http2OutboundClient::connect($loop, $race, $plaintext, $headers, $body, $complete))
            ->toThrow(InvalidArgumentException::class, 'HTTPS');
        expect(fn() => Http2OutboundClient::connect(
            $loop, $race, new ClientRequest('GET', 'HTTPS://example.test/'), $headers, $body, $complete,
            new DialOptions(tls: new TlsClientOptions('other.invalid', alpnProtocols: ['h2'])),
        ))->toThrow(InvalidArgumentException::class, 'exact requested origin');
        expect(fn() => Http2OutboundClient::connect(
            $loop, $race, new ClientRequest('GET', 'https://example.test/'), $headers, $body, $complete,
            new DialOptions(tls: new TlsClientOptions('example.test', alpnProtocols: ['h2', 'http/1.1'])),
        ))->toThrow(InvalidArgumentException::class, 'h2-only ALPN');
        expect($race->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($executor->diagnostics()['submitted'])->toBe(0);
    } finally {
        $race->close();
        $dialer->close();
        $executor->close();
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('runs one real verified TLS h2 GET against a separate reference frame-speaking server', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-h2-peer-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0700))->toBeTrue();
    $config = $directory . '/openssl.cnf';
    $certFile = $directory . '/certificate.pem';
    $keyFile = $directory . '/private.pem';
    file_put_contents($config, "[req]\ndistinguished_name=req_dn\nreq_extensions=v3_req\n[req_dn]\n[v3_req]\nsubjectAltName=IP:127.0.0.1,DNS:localhost\n");
    $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, [
        'config' => $config, 'req_extensions' => 'v3_req', 'digest_alg' => 'sha256',
    ]);
    $cert = openssl_csr_sign($csr, null, $key, 1, [
        'config' => $config, 'x509_extensions' => 'v3_req', 'digest_alg' => 'sha256',
    ]);
    expect($key)->not->toBeFalse()->and($csr)->not->toBeFalse()->and($cert)->not->toBeFalse();
    openssl_x509_export($cert, $certificate);
    openssl_pkey_export($key, $private);
    file_put_contents($certFile, $certificate);
    file_put_contents($keyFile, $private);

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $dialer = new Dialer($loop);
    $race = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $listener = null;
    $peers = [];
    $requests = [];
    $status = null;
    $body = '';
    $completed = 0;
    $failure = null;
    $watchdog = $loop->delay(3.0, static function () use ($loop): void { $loop->stop(); });

    try {
        $listener = TcpListener::bind(
            '127.0.0.1:0',
            new ListenerOptions(maxConnections: 2),
            new ConnectionLimits(),
            new TlsOptions($certFile, $keyFile, alpnProtocols: ['h2']),
        );
        $listener->start($loop, static function (Connection $peer) use (&$peers, &$requests): void {
            $peers[] = $peer;
            $buffer = '';
            $preface = false;
            $frames = new FrameParser();
            $peer->onData(static function (Connection $server) use (&$buffer, &$preface, &$requests, $frames): void {
                $buffer .= $server->read();
                if (!$preface) {
                    if (strlen($buffer) < strlen(Http2Connection::CLIENT_PREFACE)) {
                        return;
                    }
                    expect(str_starts_with($buffer, Http2Connection::CLIENT_PREFACE))->toBeTrue();
                    $buffer = substr($buffer, strlen(Http2Connection::CLIENT_PREFACE));
                    $preface = true;
                    $server->write(FrameWriter::encode(FrameWriter::settings()));
                }
                foreach ($frames->push($buffer) as $frame) {
                    if ($frame->knownType() !== FrameType::HEADERS || $frame->streamId !== 1) {
                        continue;
                    }
                    $requests = (new Decoder())->decode($frame->payload);
                    $head = (new Encoder())->encode([[':status', '200'], ['content-type', 'text/plain']]);
                    $server->write(FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x4, 1, $head))
                        . FrameWriter::encode(new Frame(FrameType::DATA->value, 0x1, 1, 'h2-good')));
                }
                $buffer = '';
            });
        });
        $port = (int) substr(strrchr($listener->address(), ':'), 1);
        Http2OutboundClient::connect(
            $loop,
            $race,
            new ClientRequest('GET', 'https://127.0.0.1:' . $port . '/verified'),
            static function (int $code) use (&$status): void { $status = $code; },
            static function (string $chunk) use (&$body): void { $body .= $chunk; },
            static function (?Connection $unused, ?Throwable $error) use (&$completed, &$failure, $loop): void {
                expect($unused)->toBeNull();
                ++$completed;
                $failure = $error;
                $loop->stop();
            },
            new DialOptions(tls: new TlsClientOptions('127.0.0.1', caFile: $certFile, alpnProtocols: ['h2'])),
        );
        $loop->run();
        expect($completed)->toBe(1)
            ->and($failure)->toBeNull()
            ->and($status)->toBe(200)
            ->and($body)->toBe('h2-good')
            ->and($requests)->toContain([':method', 'GET'], [':path', '/verified']);
    } finally {
        $loop->cancel($watchdog);
        foreach ($peers as $peer) {
            $peer->abort();
        }
        $listener?->abortConnections();
        $listener?->close();
        $race->close();
        $dialer->close();
        $executor->close();
        unlink($certFile);
        unlink($keyFile);
        unlink($config);
        rmdir($directory);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
