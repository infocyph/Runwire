<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;
use InvalidArgumentException;

final readonly class SwooleOptions
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 9501,
        public int $workerCount = 0,
        public int $maxRequestBodyBytes = 16_777_216,
        public int $maxResponseBytes = 16_777_216,
        public bool $http2 = false,
    ) {
        if ($host === '' || str_contains($host, "\0")) {
            throw new InvalidArgumentException('Swoole host cannot be empty or contain NUL bytes.');
        }
        if ($port < 1 || $port > 65_535) {
            throw new InvalidArgumentException('Swoole port must be between 1 and 65535.');
        }
        if ($workerCount < 0 || $workerCount > 65_536) {
            throw new InvalidArgumentException('Swoole workerCount must be between 0 and 65536.');
        }
        self::validateLimit($maxRequestBodyBytes, 'Swoole request body');
        self::validateLimit($maxResponseBytes, 'Swoole response');
    }

    /** @return array<string, bool|int> */
    public function serverSettings(?WorkerRecyclePolicy $recyclePolicy = null): array
    {
        $recyclePolicy ??= new WorkerRecyclePolicy();
        $settings = [
            'package_max_length' => max(65_536, $this->maxRequestBodyBytes),
        ];
        if ($this->workerCount > 0) {
            $settings['worker_num'] = $this->workerCount;
        }
        if ($recyclePolicy->maxRequests > 0) {
            $settings['max_request'] = $recyclePolicy->maxRequests;
            $settings['max_request_grace'] = $recyclePolicy->jitterRequests;
        }
        if ($this->http2) {
            $settings['open_http2_protocol'] = true;
        }

        return $settings;
    }

    private static function validateLimit(int $bytes, string $name): void
    {
        if ($bytes < 1 || $bytes > 1_073_741_824) {
            throw new InvalidArgumentException(sprintf('%s limit must be between 1 byte and 1 GiB.', $name));
        }
    }
}
