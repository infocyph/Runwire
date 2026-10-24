<?php

declare(strict_types=1);

it('preserves each historical Runwire 2.0 user-facing guide as a navigable native RST document', function (): void {
    $root = dirname(__DIR__);
    $pages = [
        'architecture.md' => 'architecture-2.0.rst',
        'benchmarks.md' => 'benchmarks-2.0.rst',
        'coroutines.md' => 'coroutines-2.0.rst',
        'deployment.md' => 'deployment-2.0.rst',
        'getting-started.md' => 'getting-started-2.0.rst',
        'migration-2.0.md' => 'migration-history-2.0.rst',
        'release-notes-2.1.2.md' => 'release-notes-2.1.2.rst',
        'security.md' => 'security-2.0.rst',
    ];
    $index = file_get_contents($root . '/docs/index.rst');
    expect($index)->toBeString();
    foreach ($pages as $markdown => $rst) {
        $source = file_get_contents($root . '/docs/' . $markdown);
        $native = file_get_contents($root . '/docs/' . $rst);
        expect($source)->toBeString()
            ->and($native)->toBeString()
            ->and($native)->toContain('.. note::', 'unreleased development branch')
            ->and($native)->not->toContain(chr(96) . chr(96) . chr(96))
            ->and($index)->toContain(substr($rst, 0, -4));
        expect(strlen($native))->toBeGreaterThan((int) (strlen($source) / 2));

        preg_match_all('/^' . preg_quote(str_repeat(chr(96), 3), '/') . '/m', $source, $fences);
        preg_match_all('/^\.\. code-block::/m', $native, $codeBlocks);
        expect(count($fences[0]) % 2)->toBe(0)
            ->and($codeBlocks[0])->toHaveCount(intdiv(count($fences[0]), 2));

        $sourceHeadings = array_filter(explode("\n", $source), static fn(string $line): bool => str_starts_with($line, '# '));
        foreach ($sourceHeadings as $heading) {
            expect($native)->toContain(substr($heading, 2));
        }
        if (preg_match('/^\|.*\|\s*$/m', $source) === 1) {
            expect($native)->toContain('.. list-table::');
        }
    }
});
