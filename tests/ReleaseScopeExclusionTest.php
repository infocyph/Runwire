<?php

declare(strict_types=1);

it('keeps excluded WebTransport out of the Runwire 3.0 implementation and release scope', function (): void {
    $root = dirname(__DIR__);
    $plan = file_get_contents($root . '/docs/plans/runwire-3.0-unified-runtime-development-plan.md');
    $features = file_get_contents($root . '/docs/features.rst');
    $index = file_get_contents($root . '/docs/index.rst');
    $quic = file_get_contents($root . '/src/Network/QuicClientOptions.php');
    expect($plan)->toBeString()
        ->and($features)->toBeString()
        ->and($index)->toBeString()
        ->and($quic)->toBeString()
        ->and($plan)->not->toMatch('/^- \[[ x]\] \*\*U09\.(?:05|06|07|08|09|10)\*\*/m')
        ->and($features)->toContain('WebTransport is not included in Runwire 3.0')
        ->and($index)->not->toContain('webtransport-design-3.0')
        ->and($quic)->not->toContain('assertDatagramAvailable')
        ->and(is_file($root . '/docs/webtransport-design-3.0.rst'))->toBeFalse()
        ->and($plan)->not->toContain('WS/WT');
});
