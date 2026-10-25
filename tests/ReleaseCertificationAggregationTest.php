<?php

declare(strict_types=1);

it('requires every frozen-source performance, soak, consumer and security job before readiness', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    expect($workflow)->toBeString()
        ->toContain('release-evidence-gate:', 'if: always()', 'needs:')
        ->toContain('      - matched-performance', '      - candidate-soak')
        ->toContain('      - consumer-app', '      - release-guard')
        ->toContain("needs['matched-performance'].result")
        ->toContain("needs['candidate-soak'].result")
        ->toContain("needs['consumer-app'].result")
        ->toContain("needs['release-guard'].result")
        ->toContain('$matched == "success"', '$soak == "success"')
        ->toContain('$consumer == "success"', '$phpforge == "success"')
        ->toContain('release_tag_created: false', 'package_published: false')
        ->toContain('runwire-release-evidence-decision');

    expect(substr_count($workflow, "certification_ready: ("))->toBe(1)
        ->and($workflow)->toContain('At least one mandatory release certification job failed or was skipped.');
});

it('keeps release certification opt-in and prevents auto publication', function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/release-certification.yml');
    expect($workflow)->toBeString()
        ->toContain('workflow_dispatch:', 'types: [labeled]', "github.event.label.name == 'release-certification'")
        ->not->toContain('push:')
        ->not->toContain('gh release create');
});
