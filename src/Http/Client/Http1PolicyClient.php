<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Closure;
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
 * Intermediate redirect bodies are discarded incrementally; no socket or
 * response is retained across authority changes. No implicit cookie jar,
 * cross-origin credential forwarding or unsafe streaming upload replay.
 */
final class Http1PolicyClient
{
    private readonly Closure $bodyCallback;

    private readonly Closure $completion;

    private readonly Closure $headCallback;

    private int $redirects = 0;

    private int $retries = 0;

    private ClientRequest $request;

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
        ClientRequest $request,
        private readonly ClientRedirectRetryPolicy $policy,
        callable $onHeaders,
        callable $onBody,
        callable $onComplete,
        private readonly ?DialOptions $dialOptions = null,
    ) {
        $this->request = $request;
        $this->headCallback = Closure::fromCallable($onHeaders);
        $this->bodyCallback = Closure::fromCallable($onBody);
        $this->completion = Closure::fromCallable($onComplete);
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

    private function send(): void
    {
        if ($this->settled) {
            return;
        }
        $next = null;
        $headError = null;
        $responseVisible = false;
        $suppressBody = false;

        try {
            Http1OutboundClient::connect(
                $this->loop,
                $this->dialer,
                $this->request,
                function (int $status, Headers $headers) use (
                    &$next,
                    &$headError,
                    &$responseVisible,
                    &$suppressBody,
                ): void {
                    if ($this->policy->isRedirect($status) && $headers->has('location')) {
                        $suppressBody = true;
                        try {
                            $locations = $headers->all('location');
                            if (count($locations) !== 1) {
                                throw new RuntimeException('Ambiguous HTTP redirect Location headers.');
                            }
                            $next = $this->policy->redirect($this->request, $locations[0], $this->redirects);
                            if ($this->dialOptions?->tls !== null
                                && ClientRedirectRetryPolicy::origin($this->request->uri)
                                    !== ClientRedirectRetryPolicy::origin($next->uri)) {
                                throw new RuntimeException('Explicit TLS trust cannot be copied to another origin.');
                            }
                        } catch (Throwable $error) {
                            $headError = $error;
                        }

                        return;
                    }
                    $responseVisible = true;
                    ($this->headCallback)($status, $headers);
                },
                function (string $chunk) use (&$suppressBody): void {
                    if (!$suppressBody) {
                        ($this->bodyCallback)($chunk);
                    }
                },
                function (?Connection $ready, ?Throwable $error) use (
                    &$next,
                    &$headError,
                    &$responseVisible,
                ): void {
                    if ($headError !== null) {
                        $ready?->abort();
                        $this->finish(null, $headError);

                        return;
                    }
                    if ($error !== null && $this->policy->shouldRetry(
                        $this->request,
                        $this->retries,
                        $error,
                        $responseVisible || $next !== null,
                    )) {
                        $ready?->abort();
                        ++$this->retries;
                        $this->send();

                        return;
                    }
                    if ($error !== null) {
                        $ready?->abort();
                        $this->finish(null, $error);

                        return;
                    }
                    if ($next !== null) {
                        $ready?->abort();
                        ++$this->redirects;
                        $this->request = $next;
                        $this->send();

                        return;
                    }
                    $this->finish($ready, null);
                },
                $this->dialOptions,
            );
        } catch (Throwable $error) {
            $this->finish(null, $error);
        }
    }
}
