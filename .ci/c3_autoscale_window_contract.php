<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Infocyph\Runwire\Supervisor\AutoscalePolicy;
use Infocyph\Runwire\Supervisor\Internal\AutoscaleWindowController;

$gate = new AutoscaleWindowController(
    new AutoscalePolicy(minWorkers: 1, maxWorkers: 3, scaleOutQueueDepth: 1, sampleSeconds: 1, cooldownSeconds: 1),
);
if ($gate->observe(1, 0.9, 2, 10.0, 300.0, 1.0) !== 1 || $gate->pendingWindows() !== 1) {
    throw new RuntimeException('First autoscale sample incorrectly changed count.');
}
$gate->invalidateSample();
if ($gate->pendingWindows() !== 0 || $gate->observe(1, 0.9, 2, 10.0, 300.0, 2.0) !== 1
    || $gate->observe(1, 0.9, 2, 10.0, 300.0, 3.0) !== 2) {
    throw new RuntimeException('Invalid metric window did not break consecutive pressure voting.');
}
fwrite(STDOUT, "C3 autoscale telemetry safety contract passed.\n");
