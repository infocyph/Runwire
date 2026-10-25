<?php

declare(strict_types=1);

it('requires a complete canonical release preflight and exactly one candidate checkout', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    expect($workflow)->toBeString();
    $preflight = explode('  matched-performance:', explode('  release-preflight:', $workflow, 2)[1], 2)[0];
    expect($preflight)->toContain('if [ "$total" -ne 175 ]; then')
        ->toContain('Malformed 3.0 canonical tracker:')
        ->toContain('exit 1' . "\n" . '          fi');
    $matched = explode('  candidate-soak:', explode('  matched-performance:', $workflow, 2)[1], 2)[0];
    expect(substr_count($matched, '          path: candidate'))->toBe(1)
        ->and(substr_count($matched, 'name: runwire-release-matched-performance'))->toBe(1)
        ->and($matched)->toContain('actual="$(git -C candidate rev-parse HEAD)"')
        ->toContain('test "$(git -C candidate rev-parse HEAD)" = "$EXPECTED_CANDIDATE"');
});
