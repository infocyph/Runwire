<?php

declare(strict_types=1);

it('keeps privilege dropping before readiness and application bootstrap', function (): void {
    $source = file_get_contents(__DIR__ . '/../src/Supervisor/Internal/WorkerChildRuntime.php');
    expect($source)->toBeString();

    $drop = strpos($source, '$group->privilegeDropPolicy->apply();');
    $ready = strpos($source, '$context->ready();');
    $bootstrap = strpos($source, '($group->bootstrap)($context);');

    expect($drop)->not->toBeFalse()
        ->and($ready)->not->toBeFalse()
        ->and($bootstrap)->not->toBeFalse()
        ->and($drop)->toBeLessThan($ready)
        ->and($drop)->toBeLessThan($bootstrap);
});
