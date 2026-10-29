<?php

declare(strict_types=1);

it('requires an immutable candidate and plan-free release preflight', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    expect($workflow)->toBeString()
        ->toContain('release-preflight:', 'test "$(git rev-parse HEAD)" = "${CANDIDATE_SHA,,}"')
        ->toContain('test ! -d docs/plans', 'source_preflight: $precheck')
        ->toContain('$precheck == "success"', 'certification_ready: (')
        ->not->toContain('task_open', 'open_tasks', 'docs/plans/runwire-3.0')
        ->not->toContain('gh release create');
    expect(substr_count($workflow, '    needs: release-preflight'))->toBe(4);
});
