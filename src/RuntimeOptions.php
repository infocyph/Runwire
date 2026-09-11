<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

final readonly class RuntimeOptions
{
    public function __construct(
        public RuntimeDriver $driver = RuntimeDriver::AUTO,
        public OpcacheMode $opcache = OpcacheMode::AUTO,
    ) {}
}
