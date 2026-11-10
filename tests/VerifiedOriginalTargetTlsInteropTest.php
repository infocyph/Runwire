<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\Internal\VerifiedOutboundTlsPeers;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;
use Infocyph\Runwire\Network\TlsClientOptions;
use RuntimeException;
use Throwable;

/**
 * @param resource $pipe
 * @throws \RuntimeException
 */
function runwireRequiredOriginalTargetPipe(mixed $pipe): string
{
    $bytes = stream_get_contents($pipe);
    if ($bytes === false) {
        throw new RuntimeException('Independent TLS peer pipe read failed.');
    }

    return $bytes;
}

require_once __DIR__ . '/Support/TlsFixture.php';

require_once __DIR__ . '/Support/ReferenceProcess.php';

/**
 * @return array{response:string,error:?Throwable,callbacks:int,peer:?string,alpn:?string,sni:?string,origin_received:bool,origin_error:?string}
 * @throws \RuntimeException
 * @throws \OverflowException
 * @throws \InvalidArgumentException
 * @throws \LogicException
 * @throws \Throwable
 */
function runwireVerifiedOriginalTargetInterop(bool $wrongHost): array
{
    $dir = sys_get_temp_dir() . '/runwire-original-tls-' . bin2hex(random_bytes(7));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Could not prepare original-target TLS certificate fixture.');
    }
    [$cert, $key] = \Infocyph\Runwire\Tests\Support\signingPair('origin.example', ['digest_alg' => 'sha256'], ['digest_alg' => 'sha256']);
    \PHPUnit\Framework\Assert::assertIsArray(openssl_x509_parse($cert));
    openssl_x509_export($cert, $pem);
    openssl_pkey_export($key, $keyPem);
    $ca = $dir . '/ca.pem';
    $private = $dir . '/private.pem';
    file_put_contents($ca, $pem);
    file_put_contents($private, $keyPem);

    $proc = proc_open(
        ['python3', __DIR__ . '/fixtures/independent_original_target_tls_proxy.py', $ca, $private],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($proc)) {
        unlink($ca);
        unlink($private);
        rmdir($dir);

        throw new RuntimeException('Could not start independent TLS/origin reference.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $loop = new SelectLoop();
    $dialer = new Dialer($loop);
    $connection = null;
    $target = null;
    $error = null;
    $callbacks = 0;
    $response = '';
    $verifiedPeer = null;
    $alpn = null;
    $watchdog = null;

    try {
        $matches = \Infocyph\Runwire\Tests\Support\awaitReferenceReady($proc, $pipes[1], $pipes[2], 5.0, '/^READY ([0-9]+)\n/', 'TLS origin fixture startup failed: ');
        $watchdog = $loop->delay(8.0, static function () use ($loop): void {
            $loop->stop();
        });
        $dialer->connect(
            'tcp://127.0.0.1:' . $matches[1],
            static function (?Connection $ready, ?Throwable $failure) use (
                &$connection,
                &$target,
                &$error,
                &$callbacks,
                &$response,
                &$verifiedPeer,
                &$alpn,
                $loop,
                $ca,
                $wrongHost,
            ): void {
                if ($failure !== null || $ready === null) {
                    $error = $failure ?? new RuntimeException('Independent proxy TCP dial failed.');
                    $loop->stop();

                    return;
                }
                $connection = $ready;
                (new HttpConnectTunnel(
                    $loop,
                    $ready,
                    new HttpConnectHandshake(),
                    'origin.example',
                    443,
                    runwireOriginalProxyCompletion($target, $error, $callbacks, $response, $verifiedPeer, $alpn, $loop, $ca, $wrongHost),
                ))->start();
            },
        );
        $loop->run();
        $target?->abort();
        $connection?->abort();

        $output = \Infocyph\Runwire\Tests\Support\awaitReferenceExit($proc, $pipes[1], 3.0);
        $peer = runwireOriginalTargetEvidence($output);

        return [
            'response' => $response,
            'error' => $error,
            'callbacks' => $callbacks,
            'peer' => $verifiedPeer,
            'alpn' => $alpn,
            'sni' => $peer['sni'],
            'origin_received' => $peer['received'],
            'origin_error' => $peer['origin_error'],
        ];
    } finally {
        if ($watchdog !== null) {
            $loop->cancel($watchdog);
        }
        $target?->abort();
        $connection?->abort();
        $dialer->close();
        proc_terminate($proc);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        unlink($ca);
        unlink($private);
        rmdir($dir);
        expect($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    }
}

it('verifies original target TLS identity, SNI, ALPN and application bytes through independent cleartext CONNECT', function (): void {
    $result = runwireVerifiedOriginalTargetInterop(false);
    expect($result['error'])->toBeNull()
        ->and($result['callbacks'])->toBe(1)
        ->and($result['peer'])->toBe('origin.example')
        ->and($result['sni'])->toBe('origin.example')
        ->and($result['alpn'])->toBe('http/1.1')
        ->and($result['origin_received'])->toBeTrue()
        ->and($result['response'])->toBe("pong-from-original\n");
});

it('fails closed on wrong original-target certificate identity and does not send application bytes', function (): void {
    $result = runwireVerifiedOriginalTargetInterop(true);
    expect($result['error'])->toBeInstanceOf(RuntimeException::class)
        ->and($result['callbacks'])->toBe(1)
        ->and($result['peer'])->toBeNull()
        ->and($result['origin_received'])->toBeFalse()
        ->and($result['response'])->toBe('');
});

/**
 * @return \Closure(?Connection,?Throwable):void
 */
function runwireOriginalTlsCompletion(?Connection &$target, ?Throwable &$error, int &$callbacks, string &$response, ?string &$verifiedPeer, ?string &$alpn, SelectLoop $loop): \Closure
{
    return static function (?Connection $secured, ?Throwable $tlsFailure) use (
        &$target,
        &$error,
        &$callbacks,
        &$response,
        &$verifiedPeer,
        &$alpn,
        $loop,
    ): void {
        ++$callbacks;
        if ($tlsFailure !== null || $secured === null) {
            $error = $tlsFailure ?? new RuntimeException('Original TLS verification failed.');
            $loop->stop();

            return;
        }
        $target = $secured;
        $verifiedPeer = VerifiedOutboundTlsPeers::peerName($secured);
        $alpn = $secured->negotiatedProtocol();
        $secured->onData(static function (Connection $live) use (&$response, $loop): void {
            $response .= $live->read();
            if ($response === "pong-from-original\n") {
                $loop->stop();
            }
        });
        if (!$secured->write("ping-to-original\n")->accepted()) {
            $error = new RuntimeException('Original TLS request was not accepted.');
            $loop->stop();
        }
    };
}

/**
 * @return array{sni:?string,received:bool,origin_error:?string}
 * @throws \Throwable
 */
function runwireOriginalTargetEvidence(string $output): array
{
    $lines = array_values(array_filter(explode("\n", trim($output))));
    $peer = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
    \PHPUnit\Framework\Assert::assertIsArray($peer);
    $sni = $peer['sni'] ?? null;
    $error = $peer['origin_error'] ?? null;
    if ($sni !== null) {
        \PHPUnit\Framework\Assert::assertIsString($sni);
    }
    if ($error !== null) {
        \PHPUnit\Framework\Assert::assertIsString($error);
    }
    \PHPUnit\Framework\Assert::assertIsBool($peer['received'] ?? null);

    return ['sni' => $sni, 'received' => $peer['received'], 'origin_error' => $error];
}

/**
 * @return \Closure(?Connection,?Throwable):void
 */
function runwireOriginalProxyCompletion(?Connection &$target, ?Throwable &$error, int &$callbacks, string &$response, ?string &$verifiedPeer, ?string &$alpn, SelectLoop $loop, string $ca, bool $wrongHost): \Closure
{
    return static function (?Connection $tunneled, ?Throwable $proxyFailure) use (
        &$target,
        &$error,
        &$callbacks,
        &$response,
        &$verifiedPeer,
        &$alpn,
        $loop,
        $ca,
        $wrongHost,
    ): void {
        if ($proxyFailure !== null || $tunneled === null) {
            $error = $proxyFailure ?? new RuntimeException('Original TLS CONNECT denied.');
            $loop->stop();

            return;
        }
        $tls = new TlsClientOptions(
            $wrongHost ? 'wrong.example' : 'origin.example',
            caFile: $ca,
            alpnProtocols: ['http/1.1'],
            handshakeTimeoutSeconds: 4.0,
        );
        $tunneled->upgradeToVerifiedTls(
            $tls,
            runwireOriginalTlsCompletion($target, $error, $callbacks, $response, $verifiedPeer, $alpn, $loop),
        );
    };
}
