<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/benchmarks/adaptive_promotion_matrix.php';

it('publishes adaptive promotion JSON without following predictable temporary symlinks', function (): void {
    $root = sys_get_temp_dir() . '/runwire-adaptive-atomic-' . bin2hex(random_bytes(5));
    expect(mkdir($root, 0700))->toBeTrue();
    $outside = tempnam(sys_get_temp_dir(), 'runwire-adaptive-victim-');
    \PHPUnit\Framework\Assert::assertIsString($outside);
    file_put_contents($outside, 'unchanged');
    $target = $root . '/summary.json';
    $legacy = $target . '.tmp';
    expect(symlink($outside, $legacy))->toBeTrue();

    try {
        adaptivePromotionWriteJson($target, ['passed' => true]);
        expect(json_decode((string) file_get_contents($target), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['passed' => true]);
        adaptivePromotionWriteJson($target, ['passed' => false]);
        expect(json_decode((string) file_get_contents($target), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['passed' => false])
            ->and(file_get_contents($outside))->toBe('unchanged')
            ->and(is_link($legacy))->toBeTrue()
            ->and(glob($root . '/runwire-promotion-*'))->toBe([]);
    } finally {
        unlink($legacy);
        if (is_file($target)) {
            unlink($target);
        }
        unlink($outside);
        rmdir($root);
    }
});
