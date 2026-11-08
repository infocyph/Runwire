<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\DevelopmentWatchPolicy;
use Infocyph\Runwire\Runtime\Internal\DevelopmentWatcher;

it('contains snapshot capacity failures without invoking reload callbacks', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-development-watch-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0700))->toBeTrue();

    $first = $directory . '/first.php';
    $second = $directory . '/second.php';
    file_put_contents($first, '<?php');
    file_put_contents($second, '<?php');

    $reloads = 0;
    $watcher = new DevelopmentWatcher(
        new SelectLoop(),
        new DevelopmentWatchPolicy(
            enabled: true,
            paths: [$directory],
            pollIntervalSeconds: 0.05,
            debounceSeconds: 0.01,
            maxFiles: 1,
        ),
        static function () use (&$reloads): void {
            ++$reloads;
        },
    );

    try {
        $watcher->start();
        expect($watcher->running())->toBeTrue()
            ->and($watcher->failureCount())->toBe(1)
            ->and($reloads)->toBe(0);
    } finally {
        $watcher->stop();
        unlink($first);
        unlink($second);
        rmdir($directory);
    }
    expect($watcher->running())->toBeFalse();
});
