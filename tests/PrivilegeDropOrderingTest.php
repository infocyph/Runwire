<?php

declare(strict_types=1);

it('keeps privilege dropping before readiness and application bootstrap', function (): void {
    $source = file_get_contents(__DIR__ . '/../src/Supervisor/Internal/WorkerChildRuntime.php');
    \PHPUnit\Framework\Assert::assertIsString($source);

    $drop = strpos($source, '$group->privilegeDropPolicy->apply();');
    $ready = strpos($source, '$context->ready();');
    $bootstrap = strpos($source, '($group->bootstrap)($context);');

    if ($drop === false || $ready === false || $bootstrap === false) {
        throw new \RuntimeException('Required privilege-drop or readiness markers are missing.');
    }
    expect($drop)->toBeLessThan($ready)
        ->and($drop)->toBeLessThan($bootstrap);
});
