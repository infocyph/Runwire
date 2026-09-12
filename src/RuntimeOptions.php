<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

final readonly class RuntimeOptions
{
    public function __construct(
        public RuntimeDriver $driver = RuntimeDriver::AUTO,
        public OpcacheMode $opcache = OpcacheMode::AUTO,
        public FpmOptions $fpm = new FpmOptions(),
        public FrankenPhpOptions $frankenPhp = new FrankenPhpOptions(),
        public SwooleOptions $swoole = new SwooleOptions(),
    ) {}
}
