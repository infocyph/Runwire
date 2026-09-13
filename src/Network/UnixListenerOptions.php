<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

final readonly class UnixListenerOptions
{
    public function __construct(
        public ListenerOptions $listener = new ListenerOptions(),
        public bool $removeStaleSocket = false,
        public ?int $permissions = null,
        public bool $unlinkOnClose = true,
    ) {
        if ($permissions !== null && ($permissions < 0 || $permissions > 0o777)) {
            throw new InvalidArgumentException('Unix socket permissions must be between 0000 and 0777.');
        }
        if ($listener->reusePort) {
            throw new InvalidArgumentException('SO_REUSEPORT is only supported for internet-domain listeners.');
        }
    }
}
