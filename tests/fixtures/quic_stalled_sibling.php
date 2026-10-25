<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\QuicClientSession;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 4 || filter_var($argv[2], FILTER_VALIDATE_INT) === false) {
    throw new InvalidArgumentException('Expected independent QUIC host, port and trusted CA.');
}

$loop = new SelectLoop();
$session = new QuicClientSession($loop, 8);
$failure = null;
$complete = false;
$siblingBefore = false;
$siblingAfter = false;
$resetCompleted = false;
$timeout = null;

$fail = static function (Throwable $error) use (&$failure, &$timeout, $loop, $session): void {
    $failure ??= $error;
    if (is_int($timeout)) {
        $loop->cancel($timeout);
    }
    $session->dispose();
};
$timeout = $loop->delay(8.0, static function () use ($fail): void {
    $fail(new RuntimeException('Independent QUIC stalled-peer and sibling-progress test timed out.'));
});

/** @param callable():void $success */
$echo = static function (string $payload, callable $success) use ($session, $loop, $fail): void {
    $session->command(
        'open_bidi',
        0,
        '',
        false,
        static function (mixed $opened, ?Throwable $error) use ($session, $loop, $fail, $payload, $success): void {
            if ($error !== null || !is_array($opened) || !is_int($opened['stream'] ?? null)) {
                $fail($error ?? new RuntimeException('QUIC sibling failed to open.'));

                return;
            }
            $stream = $opened['stream'];
            $session->command(
                'write',
                $stream,
                $payload,
                true,
                static function (mixed $written, ?Throwable $error) use ($session, $loop, $fail, $stream, $payload, $success): void {
                    if ($error !== null || !is_array($written) || ($written['written'] ?? null) !== strlen($payload)) {
                        $fail($error ?? new RuntimeException('QUIC sibling short or failed write.'));

                        return;
                    }

                    $received = '';
                    $poll = null;
                    $poll = static function () use (&$poll, &$received, $session, $loop, $fail, $stream, $payload, $success): void {
                        $session->command(
                            'read',
                            $stream,
                            '',
                            false,
                            static function (mixed $result, ?Throwable $error) use (&$poll, &$received, $loop, $fail, $payload, $success): void {
                                if ($error !== null || !is_array($result) || !is_string($result['data'] ?? null)) {
                                    $fail($error ?? new RuntimeException('QUIC peer sibling returned malformed read.'));

                                    return;
                                }
                                $bytes = base64_decode($result['data'], true);
                                if (!is_string($bytes) || strlen($received) + strlen($bytes) > 65_536) {
                                    $fail(new RuntimeException('QUIC sibling response overflow or invalid base64.'));

                                    return;
                                }
                                $received .= $bytes;
                                if (($result['eof'] ?? null) !== true) {
                                    $loop->delay(0.01, $poll);

                                    return;
                                }
                                if ($received !== $payload) {
                                    $fail(new RuntimeException('Independent QUIC peer sibling response is not byte-exact.'));

                                    return;
                                }

                                $success();
                            },
                        );
                    };
                    $poll();
                },
            );
        },
    );
};

try {
    $session->connect(
        $argv[1],
        (int) $argv[2],
        new QuicClientOptions(
            'localhost',
            alpnProtocols: ['runwire-echo'],
            caFile: $argv[3],
            connectTimeoutMilliseconds: 4_000,
            maxOpenStreams: 8,
        ),
        static function (mixed $connected, ?Throwable $error) use (
            $session,
            $loop,
            $fail,
            $echo,
            &$siblingBefore,
            &$siblingAfter,
            &$resetCompleted,
            &$complete,
            &$timeout,
        ): void {
            if ($error !== null || !is_array($connected) || ($connected['verified'] ?? null) !== true
                || ($connected['alpn'] ?? null) !== 'runwire-echo') {
                $fail($error ?? new RuntimeException('Independent QUIC peer handshake verification failed.'));

                return;
            }
            $session->command(
                'open_bidi',
                0,
                '',
                false,
                static function (mixed $opened, ?Throwable $error) use (
                    $session,
                    $loop,
                    $fail,
                    $echo,
                    &$siblingBefore,
                    &$siblingAfter,
                    &$resetCompleted,
                    &$complete,
                    &$timeout,
                ): void {
                    if ($error !== null || !is_array($opened) || !is_int($opened['stream'] ?? null)) {
                        $fail($error ?? new RuntimeException('Stalled QUIC peer stream failed admission.'));

                        return;
                    }
                    $stalled = $opened['stream'];
                    $payload = 'STALL:do-not-echo-before-reset';
                    $session->command(
                        'write',
                        $stalled,
                        $payload,
                        false,
                        static function (mixed $written, ?Throwable $error) use (
                            $session,
                            $loop,
                            $fail,
                            $echo,
                            $stalled,
                            $payload,
                            &$siblingBefore,
                            &$siblingAfter,
                            &$resetCompleted,
                            &$complete,
                            &$timeout,
                        ): void {
                            if ($error !== null || !is_array($written) || ($written['written'] ?? null) !== strlen($payload)) {
                                $fail($error ?? new RuntimeException('Stalled QUIC stream could not send request.'));

                                return;
                            }
                            $echo('LIVE:progress-while-stalled', static function () use (
                                $session,
                                $loop,
                                $fail,
                                $echo,
                                $stalled,
                                &$siblingBefore,
                                &$siblingAfter,
                                &$resetCompleted,
                                &$complete,
                                &$timeout,
                            ): void {
                                $siblingBefore = true;
                                if (!$session->active()) {
                                    $fail(new RuntimeException('Stalled QUIC stream destroyed a healthy sibling session.'));

                                    return;
                                }
                                $session->command(
                                    'reset',
                                    $stalled,
                                    '',
                                    false,
                                    static function (mixed $result, ?Throwable $error) use (
                                        $session,
                                        $loop,
                                        $fail,
                                        $echo,
                                        &$siblingAfter,
                                        &$resetCompleted,
                                        &$complete,
                                        &$timeout,
                                    ): void {
                                        if ($error !== null || !is_array($result)
                                            || ($result['reset'] ?? null) !== true || !$session->active()) {
                                            $fail($error ?? new RuntimeException('QUIC cancellation reset harmed a sibling session.'));

                                            return;
                                        }
                                        $resetCompleted = true;
                                        $echo('LIVE:post-reset-sibling', static function () use (
                                            $session,
                                            $loop,
                                            $fail,
                                            &$siblingAfter,
                                            &$complete,
                                            &$timeout,
                                        ): void {
                                            $siblingAfter = true;
                                            $session->command(
                                                'close',
                                                0,
                                                '',
                                                false,
                                                static function (mixed $closed, ?Throwable $error) use (
                                                    $loop,
                                                    $fail,
                                                    &$complete,
                                                    &$timeout,
                                                ): void {
                                                    if ($error !== null || !is_array($closed)
                                                        || ($closed['closed'] ?? null) !== true) {
                                                        $fail($error ?? new RuntimeException('QUIC stalled-peer cleanup failed.'));

                                                        return;
                                                    }
                                                    $complete = true;
                                                    if (is_int($timeout)) {
                                                        $loop->cancel($timeout);
                                                    }
                                                },
                                            );
                                        });
                                    },
                                );
                            });
                        },
                    );
                },
            );
        },
    );

    $loop->run();
    if ($failure !== null) {
        throw $failure;
    }
    if (!$complete || !$siblingBefore || !$siblingAfter || !$resetCompleted
        || $session->active() || $loop->diagnostics()->readWatchers !== 0
        || $loop->diagnostics()->writeWatchers !== 0
        || $loop->diagnostics()->timersActive !== 0) {
        throw new RuntimeException('Stalled QUIC peer failed reset isolation or resource cleanup.');
    }

    fwrite(STDOUT, json_encode([
        'verified' => true,
        'independent_aioquic_peer' => true,
        'stalled_stream_reset' => true,
        'sibling_echo_during_stall' => true,
        'post_reset_sibling_echo' => true,
        'native_worker_kept_alive' => true,
        'zero_remaining_watchers' => true,
    ], JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    $session->dispose();
}
