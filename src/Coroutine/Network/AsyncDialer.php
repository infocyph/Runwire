<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Network;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
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

    /** Await one established connection and attach coroutine callback ownership. */
    public function connect(DialEndpoint|string $endpoint, ?DialOptions $options = null): AsyncConnection
    {
        $token = $this->scope->cancellation();
        $token->throwIfCancelled();
        $options ??= new DialOptions();
        $deferred = $this->scope->deferred();
        $job = $this->dialer->connect(
            $endpoint,
            static function (?Connection $connection, ?Throwable $error) use ($deferred): void {
                if ($error !== null) {
                    $deferred->reject($error);

                    return;
                }
                if ($connection === null) {
                    $deferred->reject(new LogicException('Dialer completed without a connection.'));

                    return;
                }

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

        try {
            $connection = $deferred->future()->await();
            if (!$connection instanceof Connection) {
                throw new LogicException('Dialer produced an invalid connection value.');
            }

            try {
                return new AsyncConnection($connection, $this->scope);
            } catch (Throwable $error) {
                $connection->abort();

                throw $error;
            }
        } catch (Throwable $error) {
            $this->dialer->cancel($job);

            throw $error;
        }
    }
}
