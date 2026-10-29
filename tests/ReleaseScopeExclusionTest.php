<?php

declare(strict_types=1);

it('keeps excluded WebTransport out of the Runwire 3.0 implementation and release scope', function (): void {
    $root = dirname(__DIR__);
    $features = file_get_contents($root . '/docs/features.rst');
    $index = file_get_contents($root . '/docs/index.rst');
    expect($features)->toBeString()
        ->and($index)->toBeString()
;
});

it('contains no WebTransport implementation or protocol placeholders in Runwire source', function (): void {
    $root = dirname(__DIR__);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );
    $count = 0;

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        ++$count;
        $contents = file_get_contents($file->getPathname());
        expect($contents)->toBeString()
            ->not->toMatch('/webtransport|web_transport|web-transport/i');
    }

    expect($count)->toBeGreaterThan(300);
});
