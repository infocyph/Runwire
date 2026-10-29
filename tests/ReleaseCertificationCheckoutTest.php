<?php

declare(strict_types=1);

it('checks out the exact matched benchmark candidate in its own working tree', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    expect($workflow)->toBeString()->toContain('  matched-performance:', '  candidate-soak:');
    $matched = explode('  candidate-soak:', explode('  matched-performance:', $workflow, 2)[1], 2)[0];
    $checkout = explode(
        '      - name: Reject an unpinned, incomplete or incorrect candidate SHA',
        explode('      - name: Checkout candidate exact head', $matched, 2)[1],
        2,
    )[0];
    expect($checkout)->toContain('uses: actions/checkout@v7')
        ->toContain('          path: candidate')
        ->and($matched)->toContain('actual="$(git -C candidate rev-parse HEAD)"')
        ->toContain('test "$(git -C candidate rev-parse HEAD)" = "$EXPECTED_CANDIDATE"')
        ->toContain('test "$(git -C baseline rev-parse HEAD)" = "$BASELINE_SHA"');
});

it('collects evidence only for an immutable, successfully qualified candidate', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    expect($workflow)->toBeString()
        ->toContain('test ! -d docs/plans')
        ->toContain('source_preflight: $precheck')
        ->toContain('certification_ready: (')
        ->toContain('$precheck == "success"', '$matched == "success"', '$soak == "success"')
        ->not->toContain('open_tasks', 'task_open');
});
