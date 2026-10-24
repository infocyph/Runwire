<?php

declare(strict_types=1);

it('requires native reStructuredText Sphinx pages and valid Read the Docs pointers', function (): void {
    $root = dirname(__DIR__);
    $configuration = file_get_contents($root . '/docs/conf.py');
    $index = file_get_contents($root . '/docs/index.rst');
    $readthedocs = file_get_contents($root . '/.readthedocs.yaml');
    $tracker = file_get_contents($root . '/docs/plans/runwire-3.0-unified-runtime-development-plan.md');

    expect($configuration)->toContain('source_suffix = {".rst": "restructuredtext"}')
        ->not->toContain('"myst_parser"');
    expect($index)->toContain('.. toctree::', 'start', 'features', 'architecture');
    foreach (['start', 'features', 'architecture'] as $page) {
        expect(is_file($root . '/docs/' . $page . '.rst'))->toBeTrue();
    }
    expect($readthedocs)->toContain('version: 2', 'configuration: docs/conf.py');
    expect($tracker)->toContain('reStructuredText (.rst)');
});
