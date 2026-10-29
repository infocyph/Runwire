<?php

declare(strict_types=1);

it('never certifies the default branch instead of a pinned release candidate', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    expect($workflow)->toBeString()
        ->toContain('candidate_sha:', 'required: true', 'CANDIDATE_SHA:', 'BASELINE_SHA:')
        ->toContain('github.event.pull_request.head.sha')
        ->not->toContain('github.event.pull_request.head.sha || github.sha')
        ->not->toContain('2.2-candidate')
        ->toContain('RUNWIRE_RUNTIME_VERSION="3.0.0-candidate"');
    $selected = '$' . '{{ inputs.candidate_sha || github.event.pull_request.head.sha }}';
    expect(substr_count($workflow, $selected))->toBeGreaterThanOrEqual(10)
        ->and(substr_count($workflow, '^[0-9a-fA-F]{40}$'))->toBeGreaterThanOrEqual(3)
        ->and($workflow)->toContain('1800 "$server_pid"', 'Run exact PHPForge release guard');
});

it('keeps immutable published 2.1.2 as the release performance baseline', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    expect($workflow)->toBeString()
        ->toContain('BASELINE_SHA: 7e9bf20ad0737a187dfed77b723791b141b36378')
        ->toContain('test "$(git -C baseline rev-parse HEAD)" = "$BASELINE_SHA"')
        ->toContain('candidate/benchmarks/matched_release_certification.sh');
});
