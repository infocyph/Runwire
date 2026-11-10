<?php

declare(strict_types=1);

it('requires a clean source tree and a pinned candidate checkout', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    if (!is_string($workflow)) {
        throw new RuntimeException('Release certification workflow must be readable.');
    }
    $preflightSections = explode('  release-preflight:', $workflow, 2);
    if (!isset($preflightSections[1])) {
        throw new RuntimeException('Release certification must contain a preflight section.');
    }
    $preflight = explode('  matched-performance:', $preflightSections[1], 2)[0];
    expect($preflight)->toContain('test ! -d docs/plans')
        ->toContain('test "$(git rev-parse HEAD)" = "${CANDIDATE_SHA,,}"')
        ->toContain('exit 1');
    $matchedSections = explode('  matched-performance:', $workflow, 2);
    if (!isset($matchedSections[1])) {
        throw new RuntimeException('Release certification must contain matched performance.');
    }
    $matched = explode('  candidate-soak:', $matchedSections[1], 2)[0];
    expect(substr_count($matched, '          path: candidate'))->toBe(1)
        ->and(substr_count($matched, 'name: runwire-release-matched-performance'))->toBe(1)
        ->and($matched)->toContain('actual="$(git -C candidate rev-parse HEAD)"')
        ->toContain('test "$(git -C candidate rev-parse HEAD)" = "$EXPECTED_CANDIDATE"');
});
