<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Resolves A and AAAA records using the existing bounded, off-loop executor.
 *
 * The resolver does not own the executor lifetime. There is no fallback
 * to blocking DNS in the loop thread.
 */
final readonly class AsyncDnsResolver
{
    /** Reuse a loop-bound executor with explicit worker and queue limits. */
    public function __construct(private ProcessExecutor $executor) {}

    /** Cancel one pending DNS operation, completing its callback exactly once. */
    public function cancel(int $id): bool
    {
        return $this->executor->cancel($id);
    }

    /**
     * Queue a hostname resolution on worker processes without blocking the loop.
     *
     * @param callable(list<string>, ?Throwable):void $completion
     */
    public function resolve(
        string $hostname,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        $endpoint = DialEndpoint::parse('tcp://' . $hostname . ':1');
        if ($endpoint->scheme !== 'tcp' || $endpoint->isIpLiteral) {
            throw new InvalidArgumentException('DNS resolution requires a valid hostname, not an IP literal.');
        }

        return $this->executor->submit(
            'dns',
            ['host' => $hostname],
            static function (mixed $result, ?Throwable $error) use ($completion): void {
                if ($error !== null) {
                    $completion([], $error);

                    return;
                }
                if (!is_array($result)) {
                    $completion([], new RuntimeException('DNS executor returned an invalid response.'));

                    return;
                }
                $addresses = [];
                foreach ($result as $address) {
                    if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                        $completion([], new RuntimeException('DNS executor returned an invalid address.'));

                        return;
                    }
                    $addresses[$address] = $address;
                }
                if ($addresses === []) {
                    $completion([], new RuntimeException('DNS returned no A or AAAA addresses.'));

                    return;
                }
                $completion(array_values($addresses), null);
            },
            $token,
            $deadline,
        );
    }
}
