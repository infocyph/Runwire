<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Closure;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use OverflowException;
use Throwable;

/**
 * Owns bounded exactly-once terminal response observers shared by all writers.
 *
 * @internal
 */
final class ResponseTerminalState
{
    private const int MAX_OBSERVERS = 16;

    /** @var list<Closure(ResponseWriterInterface): void> */
    private array $observers = [];

    private bool $terminal = false;

    /**
     * Register one terminal observer, invoking it immediately after termination.
     */
    public function observe(ResponseWriterInterface $writer, callable $callback): void
    {
        $observer = Closure::fromCallable($callback);
        if ($this->terminal) {
            $observer($writer);

            return;
        }
        if (count($this->observers) >= self::MAX_OBSERVERS) {
            throw new OverflowException('HTTP response terminal observer limit exceeded.');
        }

        $this->observers[] = $observer;
    }

    /**
     * Transition to terminal once and invoke every registered observer.
     */
    public function terminate(ResponseWriterInterface $writer): void
    {
        if ($this->terminal) {
            return;
        }

        $this->terminal = true;
        $observers = $this->observers;
        $this->observers = [];
        $failure = null;

        foreach ($observers as $observer) {
            try {
                $observer($writer);
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
