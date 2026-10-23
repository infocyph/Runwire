<?php

declare(strict_types=1);

it('synchronizes the unified Runwire 3.0 detailed tracker and the historical 2.2 carry forward', function (): void {
    $root = dirname(__DIR__);
    $plan = file_get_contents($root . '/docs/plans/runwire-3.0-unified-runtime-development-plan.md');
    $roadmap = file_get_contents($root . '/docs/plans/runwire-3.0-unified-runtime-roadmap.md');
    expect($plan)->toBeString();
    expect($roadmap)->toBeString();

    preg_match_all('/^- \[([ x])\] \*\*(U[0-9]{2}\.[0-9]{2})\*\* (.+)$/m', $plan, $tasks, PREG_SET_ORDER);
    expect($tasks)->toHaveCount(175);
    $taskIds = [];
    $totals = [];
    $done = 0;
    foreach ($tasks as $task) {
        [, $checked, $id, $description] = $task;
        expect($taskIds)->not->toContain($id);
        $taskIds[] = $id;
        $batch = substr($id, 0, 3);
        $totals[$batch]['all'] = ($totals[$batch]['all'] ?? 0) + 1;
        $totals[$batch]['done'] = ($totals[$batch]['done'] ?? 0) + (int) ($checked === 'x');
        $done += (int) ($checked === 'x');
        expect($description)->toContain('**Acceptance:**', '**Proof:**');
        if ($checked === 'x') {
            expect($description)->not->toContain('**Proof:** PENDING');
        }
    }

    preg_match_all('/^\| (U[0-9]{2}) \| [^\n]*? \| (\d+)\/(\d+) \| (\d+) \|/m', $plan, $rows, PREG_SET_ORDER);
    expect($rows)->toHaveCount(18);
    foreach ($rows as $row) {
        [, $batch, $checked, $all, $open] = $row;
        expect((int) $all)->toBe($totals[$batch]['all'])
            ->and((int) $checked)->toBe($totals[$batch]['done'])
            ->and((int) $open)->toBe($totals[$batch]['all'] - $totals[$batch]['done']);
    }
    $total = count($tasks);
    $open = $total - $done;
    $pct = number_format(100 * $done / $total, 1, '.', '');
    expect($plan)->toContain(
        '| **TOTAL** | **Runwire 3.0.0 unified runtime** | **' . $done . '/' . $total . '** | **' . $open . '** |',
        '**Progress counter:** ' . $done . '/' . $total . ' (' . $pct . '% complete), ' . $open . ' open.',
    );

    expect($roadmap)->toContain(
        '**' . $done . '/175 accepted tasks, ' . $open . ' open across 18 batches**',
        '| **TOTAL** | **Full unified-runtime release program** | **' . $open . '** | **' . $done . '/175',
    );

    // The deleted 2.2 plan remains immutable at commit 7afd11e.
    // Pin all 20 unfinished IDs to reject incomplete 3.0 carry-forward.
    $unfinished = [
        'R0.06', 'R0.07', 'R1.08', 'R3.09',
        'R4.02', 'R4.04', 'R4.09',
        'R5.02', 'R5.03', 'R5.04', 'R5.05', 'R5.06', 'R5.07', 'R5.08',
        'R6.02', 'R8.05', 'R8.10', 'R9.06', 'R9.07', 'R9.09',
    ];
    expect($unfinished)->toHaveCount(20);
    $evidence = file_get_contents($root . '/docs/plans/evidence/runwire-2.2/closure-critical-path.md');
    expect($evidence)->toBeString()->toContain('Original 95 task IDs: 75 checked complete');

    preg_match_all('/^\| (R[0-9]\.[0-9]{2}) \| ([^|]+) \| OPEN, fresh 3\.0 QA required \|/m', $roadmap, $mappings, PREG_SET_ORDER);
    expect($mappings)->toHaveCount(20);
    $mappedIds = [];
    foreach ($mappings as $row) {
        $mappedIds[] = $row[1];
        foreach (explode(' + ', trim($row[2])) as $target) {
            expect($taskIds)->toContain($target);
        }
    }
    sort($unfinished);
    sort($mappedIds);
    expect($mappedIds)->toBe($unfinished);
    expect($roadmap)->toContain('Read the Docs-compatible Sphinx');
    expect($plan)->toContain('Sphinx / Read the Docs complete feature documentation index');
});
