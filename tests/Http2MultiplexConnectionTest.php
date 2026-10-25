<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http2MultiplexConnection;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;

it('routes two live H2 streams over one callback-owned established connection', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    stream_set_blocking($peer, false);
    $loop = new SelectLoop();
    // The socket-pair fixture injects verified metadata; TLS and ALPN are
    // independently accepted by the real hyper-h2 reference workflow.
    $connection = new Connection($loop, $socket, negotiatedProtocol: 'h2', encrypted: true);
    $wire = '';
    $startedPeer = false;
    $requests = [];
    $responsesSent = false;
    $frames = new FrameParser();
    $decoder = new Decoder();
    $encoder = new Encoder();
    $watcher = $loop->onReadable($peer, static function () use (
        $peer, &$wire, &$startedPeer, &$requests, &$responsesSent, $frames, $decoder, $encoder,
    ): void {
        $incoming = fread($peer, 8_192);
        if (is_string($incoming)) {
            $wire .= $incoming;
        }
        if (!$startedPeer) {
            if (strlen($wire) < strlen(Http2Connection::CLIENT_PREFACE)) {
                return;
            }
            expect(str_starts_with($wire, Http2Connection::CLIENT_PREFACE))->toBeTrue();
            $wire = substr($wire, strlen(Http2Connection::CLIENT_PREFACE));
            $startedPeer = true;
            fwrite($peer, FrameWriter::encode(FrameWriter::settings()));
        }
        foreach ($frames->push($wire) as $frame) {
            if ($frame->knownType() !== FrameType::HEADERS) {
                continue;
            }
            $requests[$frame->streamId] = $decoder->decode($frame->payload);
        }
        $wire = '';
        if (count($requests) === 2 && !$responsesSent) {
            $responsesSent = true;
            $third = $encoder->encode([[':status', '200'], ['x-origin', 'shared']]);
            $first = $encoder->encode([[':status', '200'], ['x-origin', 'shared']]);
            fwrite($peer, FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x4, 3, $third))
                . FrameWriter::encode(new Frame(FrameType::DATA->value, 0x1, 3, 'second'))
                . FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x4, 1, $first))
                . FrameWriter::encode(new Frame(FrameType::DATA->value, 0x1, 1, 'first')));
        }
    });
    $codes = [];
    $bodies = [];
    $finished = [];
    $ready = 0;
    $timeout = $loop->delay(3.0, static function () use ($loop): void { $loop->stop(); });
    $owner = new Http2MultiplexConnection(
        $loop,
        $connection,
        static function (Http2MultiplexConnection $multiplex) use (
            &$codes, &$bodies, &$finished, &$ready, $loop,
        ): void {
            ++$ready;
            foreach (['one', 'two'] as $path) {
                $multiplex->request(
                    new ClientRequest('GET', 'https://example.test/' . $path),
                    static function (int $status) use (&$codes, $path): void { $codes[$path] = $status; },
                    static function (string $chunk) use (&$bodies, $path): void {
                        $bodies[$path] = ($bodies[$path] ?? '') . $chunk;
                    },
                    static function (?Throwable $error) use (&$finished, $path, $loop): void {
                        $finished[$path] = $error;
                        if (count($finished) === 2) {
                            $loop->stop();
                        }
                    },
                );
            }
        },
        maxConcurrentStreams: 2,
    );
    try {
        $owner->start();
        $loop->run();
        expect($ready)->toBe(1)
            ->and($finished)->toHaveCount(2)
            ->and($finished['one'])->toBeNull()
            ->and($finished['two'])->toBeNull()
            ->and($codes)->toBe(['two' => 200, 'one' => 200])
            ->and($bodies)->toBe(['two' => 'second', 'one' => 'first'])
            ->and($requests)->toHaveCount(2)
            ->and($owner->activeCount())->toBe(0);
    } finally {
        $owner->close();
        $loop->cancel($timeout);
        $loop->cancel($watcher);
        fclose($peer);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('sends stream-local CANCEL without terminating the sibling live H2 exchange', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    stream_set_blocking($peer, false);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket, negotiatedProtocol: 'h2', encrypted: true);
    $wire = '';
    $peerStarted = false;
    $frameParser = new FrameParser();
    $resetSeen = false;
    $onReadable = $loop->onReadable($peer, static function () use (
        $peer, &$wire, &$peerStarted, &$resetSeen, $frameParser,
    ): void {
        $chunk = fread($peer, 8_192);
        if (is_string($chunk)) {
            $wire .= $chunk;
        }
        if (!$peerStarted) {
            if (strlen($wire) < strlen(Http2Connection::CLIENT_PREFACE)) {
                return;
            }
            $peerStarted = true;
            $wire = substr($wire, strlen(Http2Connection::CLIENT_PREFACE));
            fwrite($peer, FrameWriter::encode(FrameWriter::settings()));
        }
        foreach ($frameParser->push($wire) as $frame) {
            if ($frame->knownType() === FrameType::RST_STREAM && $frame->streamId === 1 && !$resetSeen) {
                $resetSeen = true;
                $head = (new Encoder())->encode([[':status', '200']]);
                fwrite($peer, FrameWriter::encode(new Frame(FrameType::DATA->value, 0x1, 1, 'late'))
                    . FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x4, 3, $head))
                    . FrameWriter::encode(new Frame(FrameType::DATA->value, 0x1, 3, 'alive')));
            }
        }
        $wire = '';
    });
    $errors = [];
    $body = '';
    $timeout = $loop->delay(3.0, static function () use ($loop): void { $loop->stop(); });
    $owner = new Http2MultiplexConnection(
        $loop,
        $connection,
        static function (Http2MultiplexConnection $mux) use (&$errors, &$body, $loop): void {
            $first = $mux->request(
                new ClientRequest('GET', 'https://example.test/first'),
                static function (): void {},
                static function (): void {},
                static function (?Throwable $error) use (&$errors): void { $errors['first'] = $error; },
            );
            $mux->request(
                new ClientRequest('GET', 'https://example.test/second'),
                static function (): void {},
                static function (string $part) use (&$body): void { $body .= $part; },
                static function (?Throwable $error) use (&$errors, $loop): void {
                    $errors['second'] = $error;
                    $loop->stop();
                },
            );
            $mux->cancel($first);
        },
    );
    try {
        $owner->start();
        $loop->run();
        expect($resetSeen)->toBeTrue()
            ->and($errors)->toHaveCount(2)
            ->and($errors['first'])->toBeInstanceOf(RuntimeException::class)
            ->and($errors['second'])->toBeNull()
            ->and($body)->toBe('alive')
            ->and($owner->activeCount())->toBe(0);
    } finally {
        $owner->close();
        $loop->cancel($timeout);
        $loop->cancel($onReadable);
        fclose($peer);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
