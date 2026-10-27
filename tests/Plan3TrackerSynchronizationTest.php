<?php

declare(strict_types=1);

it('synchronizes the unified Runwire 3.0 detailed tracker and the historical 2.2 carry forward', function (): void {
    $root = dirname(__DIR__);
    $plan = file_get_contents($root . '/docs/plans/runwire-3.0-unified-runtime-development-plan.md');
    $roadmap = file_get_contents($root . '/docs/plans/runwire-3.0-unified-runtime-roadmap.md');
    expect($plan)->toBeString();
    expect($roadmap)->toBeString();

    preg_match_all('/^- \[([ x])\] \*\*(U[0-9]{2}\.[0-9]{2})\*\* (.+)$/m', $plan, $tasks, PREG_SET_ORDER);
    expect($tasks)->toHaveCount(167);
    expect(array_column($tasks, 2))->not->toContain('U16.02', 'U16.16');
    $taskIds = [];
    $acceptedTaskIds = [];
    $totals = [];
    $done = 0;
    foreach ($tasks as $task) {
        [, $checked, $id, $description] = $task;
        expect($taskIds)->not->toContain($id);
        $taskIds[] = $id;
        if ($checked === 'x') {
            $acceptedTaskIds[] = $id;
        }
        $batch = substr($id, 0, 3);
        $totals[$batch]['all'] = ($totals[$batch]['all'] ?? 0) + 1;
        $totals[$batch]['done'] = ($totals[$batch]['done'] ?? 0) + (int) ($checked === 'x');
        $done += (int) ($checked === 'x');
        expect($description)->toContain('**Acceptance:**', '**Proof:**');
        if ($checked === 'x') {
            expect($description)->not->toContain('**Proof:** PENDING')
                ->toContain('https://github.com/infocyph/Runwire/actions/runs/');
        }
    }

    preg_match_all('/^\| (U[0-9]{2}) \| [^\n]*? \| (\d+)\/(\d+) \| (\d+) \| ([^|]+) \|/m', $plan, $rows, PREG_SET_ORDER);
    expect($rows)->toHaveCount(18);
    foreach ($rows as $row) {
        [, $batch, $checked, $all, $open, $status] = $row;
        expect((int) $all)->toBe($totals[$batch]['all'])
            ->and((int) $checked)->toBe($totals[$batch]['done'])
            ->and((int) $open)->toBe($totals[$batch]['all'] - $totals[$batch]['done']);

        $status = strtolower(trim($status));
        if ((int) $checked === (int) $all) {
            expect(in_array($status, ['done', 'complete'], true))->toBeTrue();
        } elseif ((int) $checked > 0) {
            expect($status)->toContain('in progress');
        } else {
            expect($status)->toBe('not started');
        }
    }
    $total = count($tasks);
    $open = $total - $done;
    $pct = number_format(100 * $done / $total, 1, '.', '');
    expect($plan)->toContain(
        '| **TOTAL** | **Runwire 3.0.0 unified runtime** | **' . $done . '/' . $total . '** | **' . $open . '** |',
        '**Progress counter:** ' . $done . '/' . $total . ' (' . $pct . '% complete), ' . $open . ' open.',
    );

    expect($roadmap)->toContain(
        '**' . $done . '/' . $total . ' accepted tasks, ' . $open . ' open across 18 batches**',
        '| **TOTAL** | **Full unified-runtime release program** | **' . $open . '** | **' . $done . '/' . $total,
    );

    // Validate each roadmap batch, not just the aggregate total. This catches
    // stale divisions when the canonical plan accepts a new task.
    preg_match_all('/^\| (U[0-9]{2}) \| [^\n]*? \| (\d+) \| (\d+)\/(\d+) \|$/m', $roadmap, $roadmapRows, PREG_SET_ORDER);
    expect($roadmapRows)->toHaveCount(18);
    foreach ($roadmapRows as $row) {
        [, $batch, $remaining, $completed, $planned] = $row;
        expect((int) $remaining)->toBe($totals[$batch]['all'] - $totals[$batch]['done'])
            ->and((int) $completed)->toBe($totals[$batch]['done'])
            ->and((int) $planned)->toBe($totals[$batch]['all']);
    }

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

    preg_match_all('/^\| (R[0-9]\.[0-9]{2}) \| ([^|]+) \| ([^|]+) \|$/m', $roadmap, $mappings, PREG_SET_ORDER);
    expect($mappings)->toHaveCount(20);
    $mappedIds = [];
    foreach ($mappings as $row) {
        $mappedIds[] = $row[1];
        $targets = explode(' + ', trim($row[2]));
        $accepted = 0;
        foreach ($targets as $target) {
            expect($taskIds)->toContain($target);
            if (in_array($target, $acceptedTaskIds, true)) {
                ++$accepted;
            }
        }

        $expectedState = $accepted === 0
            ? 'OPEN'
            : ($accepted === count($targets) ? 'ACCEPTED' : 'PARTIAL');
        expect(trim($row[3]))->toStartWith($expectedState);
    }
    sort($unfinished);
    sort($mappedIds);
    expect($mappedIds)->toBe($unfinished);
    expect($roadmap)->toContain('Read the Docs-compatible Sphinx');
    expect($plan)->toContain('Sphinx / Read the Docs complete feature documentation index');
});


it('enforces every canonical U division prose tracker against its checked task IDs', function (): void {
    $plan = file_get_contents(dirname(__DIR__) . '/docs/plans/runwire-3.0-unified-runtime-development-plan.md');
    expect($plan)->toBeString();
    preg_match_all('/^### (U[0-9]{2}) —[^\n]*\n([\s\S]*?)(?=^### U[0-9]{2} —|^## |\z)/m', $plan, $sections, PREG_SET_ORDER);
    expect($sections)->toHaveCount(18);
    foreach ($sections as $section) {
        preg_match_all('/^- \[([ x])\] \*\*(U[0-9]{2}\.[0-9]{2})\*\*/m', $section[2], $tasks, PREG_SET_ORDER);
        $done = count(array_filter($tasks, static fn(array $task): bool => $task[1] === 'x'));
        $open = count($tasks) - $done;
        preg_match('/\*\*Batch tracker:\*\* ([0-9]+)\/([0-9]+) accepted; ([0-9]+) open/', $section[2], $tracker);
        expect($tracker)->not->toBeEmpty()
            ->and((int) $tracker[1])->toBe($done)
            ->and((int) $tracker[2])->toBe(count($tasks))
            ->and((int) $tracker[3])->toBe($open);
    }
});


it('keeps all five original closure groups disjoint and their open counts synchronized', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/docs/plans/runwire-3.0-unified-runtime-development-plan.md');
    expect($source)->toBeString();
    preg_match_all('/^- \[([ x])\] \*\*(U[0-9]{2}\.[0-9]{2})\*\*/m', $source, $items, PREG_SET_ORDER);
    $states = [];
    foreach ($items as $item) {
        $states[$item[2]] = $item[1];
    }

    preg_match_all('/^\| C([1-5]) \| [^|]+ \| ([^|]+) \| ([0-9]+) \|/m', $source, $groups, PREG_SET_ORDER);
    expect($groups)->toHaveCount(5);
    $assigned = [];
    $stillOpen = 0;
    foreach ($groups as $group) {
        $ids = array_map('trim', explode(',', $group[2]));
        $unaccepted = 0;
        foreach ($ids as $id) {
            expect($states)->toHaveKey($id);
            expect($assigned)->not->toContain($id);
            $assigned[] = $id;
            $unaccepted += (int) ($states[$id] === ' ');
        }
        if ($group[1] === '1') {
            expect($ids)->toHaveCount(11);
        }
        expect((int) $group[3])->toBe($unaccepted);
        $stillOpen += $unaccepted;
    }
    expect($assigned)->toHaveCount(45)
        ->and($stillOpen)->toBe(count(array_filter($states, static fn(string $state): bool => $state === ' ')));
});
