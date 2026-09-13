<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

interface LoopDiagnosticsProviderInterface
{
    public function diagnostics(): LoopDiagnosticsSnapshot;
}
