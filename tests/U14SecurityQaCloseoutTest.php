<?php

declare(strict_types=1);

it('pins the security QA closeout to its real PHPForge and locked Composer audit source', function (): void {
    $root = dirname(__DIR__);
    $evidence = file_get_contents($root . '/docs/plans/evidence/runwire-3.0/u14-security-qa-closeout.md');
    $plan = file_get_contents($root . '/docs/plans/runwire-3.0-unified-runtime-development-plan.md');
    expect($evidence)->toBeString()
        ->toContain('af7b83c00348544bf2ddc67d65c7594afd839c2e')
        ->toContain('/actions/runs/40180033609', '/actions/runs/40180033273')
        ->toContain('PHP 8.4', 'PHP 8.5', 'Audit exact locked dependencies')
        ->toContain('U17.02');
    expect($plan)->toBeString()
        ->toContain('- [x] **U14.08**', '- [x] **U14.04**')
        ->toContain('- [ ] **U17.02**');
});
