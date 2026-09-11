<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Infocyph\Runwire\Process\Command;

final readonly class PreparedCommand
{
    /**
     * @param list<string> $argv
     * @param array<string, string> $environment
     */
    public function __construct(
        public Command $command,
        public array $argv,
        public array $environment,
        public ?string $cwd,
    ) {}
}
