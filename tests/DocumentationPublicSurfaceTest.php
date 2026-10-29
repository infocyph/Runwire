<?php

declare(strict_types=1);

it('keeps the published manual free from obsolete smoke paths and build fingerprints', function (): void {
    $root = dirname(__DIR__);
    $index = file_get_contents($root . '/docs/index.rst');
    $conf = file_get_contents($root . '/docs/conf.py');
    $readme = file_get_contents($root . '/README.md');

    expect($index)->toBeString()
        ->not->toContain('Source revision:', 'runwire_source_revision')
        ->and($conf)->toBeString()
        ->toContain('html_title = "Runwire Manual"')
        ->not->toContain('runwire_source_revision', 'rst_prolog')
        ->and($readme)->toBeString()
        ->toContain('https://docs.infocyph.com/projects/Runwire');

    expect(is_file($root . '/docs/api-reference-3.0.rst'))->toBeFalse();
    expect(is_file($root . '/docs/build_api_reference.py'))->toBeFalse();

    $pages = glob($root . '/docs/*.rst') ?: [];
    expect(count($pages))->toBeGreaterThan(25);
    foreach ($pages as $path) {
        $source = file_get_contents($path);
        expect($source)->toBeString()->not->toContain('.ci/c3_', '|runwire_source_revision|', 'api-reference-3.0');
        preg_match_all('~\\btests/smoke/native_([a-z_]+)\\.php\\b~', $source, $matches);
        foreach (array_unique($matches[0]) as $relative) {
            expect(is_file($root . '/' . $relative))
                ->toBeTrue('Missing native smoke fixture: ' . $relative);
        }
        if (str_contains(basename($path), 'migration')
            || str_contains(basename($path), 'compatibility')) {
            continue;
        }
        $lines = preg_split('/\\r?\\n/', $source);
        expect($lines)->toBeArray();
        $heading = trim($lines[0] ?? '');
        if (preg_match('/^[=~-]+$/', $heading) === 1) {
            $heading = trim($lines[1] ?? '');
        }
        expect($heading)->not->toMatch('/\\b3\\.0\\b/');
    }
});
