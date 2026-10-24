<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Network;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use LogicException;
use Throwable;

/**
 * Fiber-await facade over the same DNS/racing/socket connector used by callbacks.
 */
final readonly class AsyncDialer
{
    /** Bind outgoing work to a structured scope without a second transport engine. */
    public function __construct(
        private HappyEyeballsDialer $dialer,
        private CoroutineScope $scope,
    ) {}

    /** Await an established connection, honoring both scope and call cancellation. */
    public function connect(DialEndpoint|string $endpoint, ?DialOptions $options = null): AsyncConnection
    {
        $token = $this->scope->cancellation();
        $token->throwIfCancelled();
        $options ??= new DialOptions();
        $callToken = $options->cancellation;
        $linked = null;
        $observer = null;
        $job = null;
        $completedConnection = null;

        try {
            if ($callToken !== null && $callToken !== $token) {
                $callToken->throwIfCancelled();
                $linked = CancellationSource::linked($token, $callToken->deadline());
                $observer = $callToken->onCancel(
                    static function (CancellationToken $cancelled) use ($linked): void {
                        $linked->cancel($cancelled->reason() ?? CancellationReason::HOST_CANCELLED);
                    },
                );
                $token = $linked->token();
            }
            $token->throwIfCancelled();
            $deferred = $this->scope->deferred();
            $job = $this->dialer->connect(
                $endpoint,
                static function (?Connection $connection, ?Throwable $error) use ($deferred, &$completedConnection): void {
                    if ($error !== null) {
                        $deferred->reject($error);

                        return;
                    }
                    if ($connection === null) {
                        $deferred->reject(new LogicException('Dialer completed without a connection.'));

                        return;
                    }

                    $completedConnection = $connection;
                    $deferred->resolve($connection);
                },
                new DialOptions(
                    connectTimeoutSeconds: $options->connectTimeoutSeconds,
                    deadline: $options->deadline,
                    cancellation: $token,
                    connectionLimits: $options->connectionLimits,
                    tcpNoDelay: $options->tcpNoDelay,
                    tls: $options->tls,
                ),
            );

            $connection = $deferred->future()->await();
            if (!$connection instanceof Connection) {
                throw new LogicException('Dialer produced an invalid connection value.');
            }
            $adapter = new AsyncConnection($connection, $this->scope);
            $completedConnection = null;

            return $adapter;
        } catch (Throwable $error) {
            // A winner can arrive before a cancelled Fiber resumes awaiting it.
            $completedConnection?->abort();
            if ($job !== null) {
                $this->dialer->cancel($job);
            }

            throw $error;
        } finally {
            $observer?->unsubscribe();
            $linked?->dispose();
        }
    }
}
