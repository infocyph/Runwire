<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Closure;
use Infocyph\Runwire\Http\Client\Internal\Http1PolicyAttempt;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use RuntimeException;
use Throwable;

/**
 * Explicit HTTP/1.1 redirect/retry runner with strict request ownership.
 *
 * Intermediate redirect bodies are consumed but not exposed. No cookie jar,
 * cross-origin credential forwarding or unsafe streaming-upload replay.
 */
final class Http1PolicyClient
{
    private readonly Closure $bodyCallback;

    private readonly Closure $completion;

    private readonly Closure $headCallback;

    private int $redirects = 0;

    private int $retries = 0;

    private bool $settled = false;

    private bool $started = false;

    /**
     * @param callable(int,Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Connection,?Throwable):void $onComplete
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly HappyEyeballsDialer $dialer,
        private ClientRequest $request,
        private readonly ClientRedirectRetryPolicy $policy,
        callable $onHeaders,
        callable $onBody,
        callable $onComplete,
        private readonly ?DialOptions $dialOptions = null,
    ) {
        $this->headCallback = $onHeaders(...);
        $this->bodyCallback = $onBody(...);
        $this->completion = $onComplete(...);
    }

    /** Begin one bounded series of safe HTTP/1.1 requests. */
    public function start(): void
    {
        if ($this->started) {
            throw new RuntimeException('HTTP policy client may start only once.');
        }
        $this->started = true;
        $this->send();
    }

    private function finish(?Connection $connection, ?Throwable $error): void
    {
        if ($this->settled) {
            $connection?->abort();

            return;
        }
        $this->settled = true;
        ($this->completion)($connection, $error);
    }

    private function handleCompletion(Http1PolicyAttempt $attempt, ?Connection $connection, ?Throwable $error): void
    {
        if ($attempt->headError !== null) {
            $connection?->abort();
            $this->finish(null, $attempt->headError);

            return;
        }
        if ($error !== null && $this->policy->shouldRetry(
            $this->request,
            $this->retries,
            $error,
            $attempt->responseStarted || $attempt->redirect !== null,
        )) {
            $connection?->abort();
            ++$this->retries;
            $this->send();

            return;
        }
        if ($error !== null) {
            $connection?->abort();
            $this->finish(null, $error);

            return;
        }
        if ($attempt->redirect !== null) {
            $connection?->abort();
            ++$this->redirects;
            $this->request = $attempt->redirect;
            $this->send();

            return;
        }
        $this->finish($connection, null);
    }

    private function handleHeaders(Http1PolicyAttempt $attempt, int $status, Headers $headers): void
    {
        if (!$this->policy->isRedirect($status) || !$headers->has('location')) {
            $attempt->responseStarted = true;
            ($this->headCallback)($status, $headers);

            return;
        }

        $attempt->suppressBody = true;

        try {
            $locations = $headers->all('location');
            if (count($locations) !== 1) {
                throw new RuntimeException('Ambiguous HTTP redirect Location headers.');
            }
            $redirect = $this->policy->redirect($this->request, $locations[0], $this->redirects);
            $attempt->redirect = $redirect;
            if ($this->dialOptions?->tls !== null
                && ClientRedirectRetryPolicy::origin($this->request->uri)
                    !== ClientRedirectRetryPolicy::origin($redirect->uri)) {
                throw new RuntimeException('Explicit TLS trust cannot be copied to another origin.');
            }
        } catch (Throwable $error) {
            $attempt->headError = $error;
        }
    }

    private function send(): void
    {
        if ($this->settled) {
            return;
        }
        $attempt = new Http1PolicyAttempt();

        try {
            Http1OutboundClient::connect(
                $this->loop,
                $this->dialer,
                $this->request,
                function (int $status, Headers $headers) use ($attempt): void {
                    $this->handleHeaders($attempt, $status, $headers);
                },
                function (string $bytes) use ($attempt): void {
                    if (!$attempt->suppressBody) {
                        ($this->bodyCallback)($bytes);
                    }
                },
                function (?Connection $ready, ?Throwable $error) use ($attempt): void {
                    $this->handleCompletion($attempt, $ready, $error);
                },
                $this->dialOptions,
            );
        } catch (Throwable $error) {
            $this->finish(null, $error);
        }
    }
}
