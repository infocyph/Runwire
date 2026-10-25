<?php

declare(strict_types=1);

it('blocks costly release certification whenever any canonical 3.0 task is open', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    expect($workflow)->toBeString()
        ->toContain('release-preflight:', 'task_open:')
        ->toContain('if [ "$total" -ne 175 ] || [ "$open" -ne 0 ]; then')
        ->toContain('3.0 cannot be release-certified')
        ->toContain('tracker_preflight: $precheck')
        ->toContain('($task_open == "0")', '$precheck == "success"')
        ->toContain('($task_open | tonumber? // -1)')
        ->toContain('      - release-preflight')
        ->not->toContain('gh release create');
    expect(substr_count($workflow, '    needs: release-preflight'))->toBe(4);
});

it('keeps all 175 canonical task identities unique before any release promotion', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/docs/plans/runwire-3.0-unified-runtime-development-plan.md');
    expect($source)->toBeString();
    preg_match_all('/^- \[[x ]\] \*\*(U[0-9]{2}\.[0-9]{2})\*\*/m', $source, $matches);
    expect($matches[1])->toHaveCount(175)
        ->and(array_unique($matches[1]))->toHaveCount(175);
});
