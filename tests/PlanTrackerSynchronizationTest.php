<?php

declare(strict_types=1);

it('keeps the Runwire 2.2 batch tracker consistent with its actual task checkboxes', function (): void {
    $plan = file_get_contents(dirname(__DIR__) . '/docs/plans/runwire-2.2-runtime-improvement-plan.md');
    expect($plan)->toBeString();

    preg_match_all('/^- \\[([ x])\\] \\*\\*(R[0-9]\\.[0-9]{2})\\*\\*/m', $plan, $rows, PREG_SET_ORDER);
    expect($rows)->toHaveCount(95);

    $completed = 0;
    $byBatch = [];
    $ids = [];
    $deferred = [];
    foreach ($rows as $row) {
        [, $state, $id] = $row;
        expect($ids)->not->toContain($id);
        $ids[] = $id;
        $batch = substr($id, 0, 2);
        $byBatch[$batch]['total'] = ($byBatch[$batch]['total'] ?? 0) + 1;
        $byBatch[$batch]['done'] = ($byBatch[$batch]['done'] ?? 0) + (int) ($state === 'x');
        $completed += (int) ($state === 'x');

        if (preg_match('/^R5\\.0[2-8]$/', $id) === 1) {
            expect($state)->toBe(' ');
            $deferred[] = $id;
        }
    }
    expect($deferred)->toHaveCount(7);

    preg_match_all('/^\\| (R[0-9]) \\| [^\\n]*? \\| \\[(?:~|x|↷)\\] (\\d+)\\/(\\d+)/mu', $plan, $summaries, PREG_SET_ORDER);
    expect($summaries)->toHaveCount(10);
    foreach ($summaries as $summary) {
        expect((int) $summary[2])->toBe($byBatch[$summary[1]]['done'])
            ->and((int) $summary[3])->toBe($byBatch[$summary[1]]['total']);
    }

    $inScope = count($rows) - count($deferred);
    $open = $inScope - $completed;
    $percentage = number_format(100 * $completed / $inScope, 1, '.', '');

    expect($plan)->toContain(
        '| Checked DONE with evidence | ' . $completed . ' |',
        '| **Active 2.2 remaining** | **' . $open . '** |',
        '| **In-scope completion** | **' . $completed . '/' . $inScope . ' (' . $percentage . '%)** |',
    );

    $matrix = file_get_contents(dirname(__DIR__) . '/docs/plans/evidence/runwire-2.2/final-qa-matrix.md');
    $criticalPath = file_get_contents(dirname(__DIR__) . '/docs/plans/evidence/runwire-2.2/closure-critical-path.md');
    expect($matrix)->toBeString()->toContain(
        '**' . $completed . '/' . $inScope . ' in-scope checked (' . $percentage . '%), ' . $open . ' active remaining**',
    );
    expect($criticalPath)->toBeString()->toContain(
        'Original 95 task IDs: ' . $completed . ' checked complete; **' . $open . ' active open**',
    );
});
