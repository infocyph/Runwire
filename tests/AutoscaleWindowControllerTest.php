<?php

declare(strict_types=1);

use Infocyph\Runwire\Supervisor\AutoscalePolicy;
use Infocyph\Runwire\Supervisor\Internal\AutoscaleWindowController;

it('requires sustained pressure and prevents scale-in until cooldown and stable idle windows', function (): void {
    $gate = new AutoscaleWindowController(new AutoscalePolicy(
        minWorkers: 2, maxWorkers: 6, sampleSeconds: 10, cooldownSeconds: 30, maxStepPerWindow: 2,
    ));
    expect($gate->observe(4, 0.9, 40, 300, 500, 100))->toBe(4)
        ->and($gate->pendingWindows())->toBe(1)
        ->and($gate->observe(4, 0.9, 40, 300, 500, 110))->toBe(6)
        ->and($gate->lastChangeAt())->toBe(110.0)
        ->and($gate->observe(6, 0.1, 0, 0, 0, 120))->toBe(6)
        ->and($gate->observe(6, 0.1, 0, 0, 0, 140))->toBe(6)
        ->and($gate->pendingWindows())->toBe(1)
        ->and($gate->observe(6, 0.1, 0, 0, 0, 150))->toBe(4);
});

it('rejects non-monotonic samples and resets voting when the pressure direction changes', function (): void {
    $gate = new AutoscaleWindowController(new AutoscalePolicy(
        minWorkers: 2, maxWorkers: 6, sampleSeconds: 10, cooldownSeconds: 30,
    ));
    expect($gate->observe(4, 0.85, 20, 200, 400, 10))->toBe(4)
        ->and($gate->observe(4, 0.85, 20, 200, 400, 11))->toBe(4)
        ->and($gate->pendingWindows())->toBe(1)
        ->and($gate->observe(4, 0.1, 0, 0, 0, 20))->toBe(4)
        ->and($gate->observe(4, 0.85, 20, 200, 400, 30))->toBe(4)
        ->and($gate->pendingWindows())->toBe(1)
        ->and(fn() => $gate->observe(4, 0.85, 20, 200, 400, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $gate->observe(4, 0.85, 20, 200, 400, NAN))->toThrow(InvalidArgumentException::class);
});

it('enforces fixed worker override and safe minimum/maximum correction without pressure votes', function (): void {
    $fixed = new AutoscaleWindowController(new AutoscalePolicy(
        minWorkers: 2, maxWorkers: 8, workerOverride: 3,
    ));
    expect($fixed->observe(7, 0.99, 100, 500, 800, 10))->toBe(3);
    $bounded = new AutoscaleWindowController(new AutoscalePolicy(
        minWorkers: 2, maxWorkers: 6, maxStepPerWindow: 2,
    ));
    expect($bounded->observe(1, 0.5, 0, 2, 20, 10))->toBe(2)
        ->and($bounded->observe(7, 0.5, 0, 2, 20, 20))->toBe(6);
});

it('does not oscillate during 240 alternating overload and idle windows', function (): void {
    $gate = new AutoscaleWindowController(new AutoscalePolicy(
        minWorkers: 2, maxWorkers: 8, sampleSeconds: 10, cooldownSeconds: 30,
    ), consecutiveWindows: 3);
    $workers = 4;
    for ($n = 1; $n <= 240; ++$n) {
        $high = $n % 2 === 0;
        $workers = $gate->observe(
            $workers, $high ? 0.9 : 0.1, $high ? 20 : 0,
            $high ? 100 : 0, $high ? 300 : 0, (float) ($n * 10),
        );
        expect($workers)->toBe(4)
            ->and($gate->pendingWindows())->toBeLessThanOrEqual(1);
    }
    expect(fn() => new AutoscaleWindowController(new AutoscalePolicy(), consecutiveWindows: 1))
        ->toThrow(InvalidArgumentException::class);
});
