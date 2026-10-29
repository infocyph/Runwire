<?php

declare(strict_types=1);

it('requires native reStructuredText Sphinx pages and valid Read the Docs pointers', function (): void {
    $root = dirname(__DIR__);
    $configuration = file_get_contents($root . '/docs/conf.py');
    $index = file_get_contents($root . '/docs/index.rst');
    $readthedocs = file_get_contents($root . '/.readthedocs.yaml');

    expect($configuration)->toContain('source_suffix = {".rst": "restructuredtext"}')
        ->not->toContain('"myst_parser"');
    expect($index)->toContain('.. toctree::', 'start', 'features', 'architecture');
    foreach (['start', 'features', 'architecture'] as $page) {
        expect(is_file($root . '/docs/' . $page . '.rst'))->toBeTrue();
    }
    expect($readthedocs)->toContain('version: 2', 'configuration: docs/conf.py');
    expect(is_dir($root . '/docs/plans'))->toBeFalse();
});
