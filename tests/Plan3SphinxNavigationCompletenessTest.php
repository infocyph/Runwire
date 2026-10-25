<?php

declare(strict_types=1);

it('navigates every published native RST page exactly once in the Sphinx manual', function (): void {
    $root = dirname(__DIR__) . '/docs';
    $index = file_get_contents($root . '/index.rst');
    expect($index)->toBeString()->toContain('.. toctree::');
    $count = preg_match_all('/^   ([a-z0-9][a-z0-9.-]*)$/m', $index, $matches);
    expect($count)->toBeGreaterThan(0);

    $listed = $matches[1];
    expect($listed)->toHaveCount(count(array_unique($listed)));
    $all = glob($root . '/*.rst');
    expect($all)->toBeArray();
    $expected = [];
    foreach ($all as $path) {
        $page = pathinfo($path, PATHINFO_FILENAME);
        if ($page !== 'index') {
            $expected[] = $page;
        }
    }
    sort($listed, SORT_STRING);
    sort($expected, SORT_STRING);
    expect($listed)->toBe($expected);
});

it('keeps historical manuals and explicit proxy TLS migration limitations', function (): void {
    $root = dirname(__DIR__) . '/docs';
    $index = file_get_contents($root . '/index.rst');
    $migration = file_get_contents($root . '/migration-3.0.rst');
    expect($index)->toBeString()
        ->toContain('Historical Runwire 2.0 and 2.1.x guides', 'migration-3.0', 'async-file-io-3.0')
        ->and($migration)->toBeString()
        ->toContain('Experimental HTTP CONNECT and SOCKS5 stages')
        ->toContain('not an HTTPS-through-proxy client')
        ->toContain('original target')
        ->toContain('unreleased');
});
