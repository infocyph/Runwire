<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Closure;
use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Loop\LoopInterface;

final class SignalBridge
{
    /** @var Closure(list<int>): void|null */
    private ?Closure $consumer = null;

    /** @var array<int, bool> */
    private array $pending = [];

    private bool $previousAsyncSignals = false;

    /** @var array<int, callable|int> */
    private array $previousHandlers = [];

    /** @var resource|null */
    private mixed $read = null;

    private ?int $watcherId = null;

    /** @var resource|null */
    private mixed $write = null;

    public function __construct(private readonly LoopInterface $loop) {}

    public function close(): void
    {
        $this->restoreHandlers();

        if ($this->watcherId !== null) {
            $this->loop->cancel($this->watcherId);
            $this->watcherId = null;
        }

        $this->closeStreams();
        $this->pending = [];
        $this->consumer = null;
    }

    public function normalizeChild(): void
    {
        pcntl_alarm(0);
        pcntl_async_signals(false);

        foreach ([SIGTERM, SIGINT, SIGHUP, SIGCHLD] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        if (function_exists('pcntl_sigprocmask') && !pcntl_sigprocmask(SIG_SETMASK, [])) {
            throw new SupervisorException('Unable to reset child signal mask.');
        }

        $this->closeStreams();
    }

    /**
     * @param callable(list<int>): void $consumer
     */
    public function open(callable $consumer): void
    {
        $this->consumer = Closure::fromCallable($consumer);
        $this->openWakeChannel();
        $this->installHandlers();
    }

    private function closeStreams(): void
    {
        if (is_resource($this->read)) {
            fclose($this->read);
        }

        if (is_resource($this->write)) {
            fclose($this->write);
        }

        $this->read = null;
        $this->write = null;
    }

    private function dispatch(): void
    {
        if ($this->consumer === null || $this->pending === []) {
            return;
        }

        $signals = array_map(intval(...), array_keys($this->pending));
        $this->pending = [];
        ($this->consumer)($signals);
    }

    /**
     * @param resource $stream
     */
    private function drain(mixed $stream): void
    {
        while (is_resource($stream)) {
            $chunk = fread($stream, 8_192);
            if (!is_string($chunk) || $chunk === '' || strlen($chunk) < 8_192) {
                return;
            }
        }
    }

    private function installHandlers(): void
    {
        $this->previousAsyncSignals = pcntl_async_signals();
        pcntl_async_signals(true);

        $handler = function (int $signal): void {
            $this->pending[$signal] = true;
            $this->wake();
        };

        foreach ([SIGTERM, SIGINT, SIGHUP, SIGCHLD] as $signal) {
            $this->previousHandlers[$signal] = pcntl_signal_get_handler($signal);
            if (!pcntl_signal($signal, $handler)) {
                throw new SupervisorException(sprintf(
                    'Unable to install supervisor signal handler for signal %d.',
                    $signal,
                ));
            }
        }
    }

    private function openWakeChannel(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new SupervisorException('Unable to create supervisor wake channel.');
        }

        [$this->read, $this->write] = $pair;
        if (!stream_set_blocking($this->read, false) || !stream_set_blocking($this->write, false)) {
            $this->closeStreams();

            throw new SupervisorException('Unable to configure supervisor wake channel.');
        }

        $this->watcherId = $this->loop->onReadable(
            $this->read,
            function ($stream): void {
                $this->drain($stream);
                $this->dispatch();
            },
        );
    }

    private function restoreHandlers(): void
    {
        if ($this->previousHandlers === []) {
            return;
        }

        foreach ($this->previousHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }

        $this->previousHandlers = [];
        pcntl_async_signals($this->previousAsyncSignals);
    }

    private function wake(): void
    {
        if (is_resource($this->write)) {
            fwrite($this->write, "\0");
        }
    }
}
