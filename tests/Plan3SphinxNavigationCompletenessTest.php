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

it('rejects short native RST heading underlines throughout the published manual', function (): void {
    $root = dirname(__DIR__) . '/docs';
    $pages = glob($root . '/*.rst');
    expect($pages)->toBeArray();
    foreach ($pages as $file) {
        $source = file_get_contents($file);
        expect($source)->toBeString();
        $lines = preg_split('/\r?\n/', $source);
        expect($lines)->toBeArray();
        foreach ($lines as $position => $underline) {
            if ($position < 1 || preg_match('/^([=\-~^])\1{2,}$/D', $underline) !== 1) {
                continue;
            }
            $title = $lines[$position - 1];
            if ($title === '' || preg_match('/^\s/', $title) === 1
                || preg_match('/^([=\-~^])\1{2,}$/D', $title) === 1) {
                continue;
            }
            // Sphinx measures Unicode characters, not UTF-8 bytes: a title
            // containing an en dash is wider in strlen() than in rendered text.
            $columns = preg_match_all('/./u', rtrim($title));
            expect($columns)->toBeInt();
            expect(strlen($underline))->toBeGreaterThanOrEqual(
                $columns,
                basename($file) . ':' . ($position + 1) . ' has a short title underline.',
            );
        }
    }
});

it('counts Unicode RST heading characters rather than UTF-8 bytes', function (): void {
    $heading = 'Runwire – HTTP/3';
    expect(strlen($heading))->toBeGreaterThan(16)
        ->and(preg_match_all('/./u', $heading))->toBe(16);
});
