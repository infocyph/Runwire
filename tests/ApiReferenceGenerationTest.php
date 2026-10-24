<?php

declare(strict_types=1);

it('generates a complete source-linked public PHP symbol and exception index without remote dependencies', function (): void {
    $root = dirname(__DIR__);
    $builder = $root . '/docs/build_api_reference.py';
    expect(is_file($builder))->toBeTrue();

    $output = [];
    $status = 0;
    exec('python3 ' . escapeshellarg($builder) . ' 2>&1', $output, $status);
    expect($status)->toBe(0, implode("\n", $output));

    $reference = file_get_contents($root . '/docs/_generated/public-api.inc');
    expect($reference)->toBeString()
        ->toContain('Source-linked PHP API and error reference', 'Exception catalog', 'Public method signatures:', 'public function __construct(');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );
    $files = 0;
    $methods = 0;
    $properties = 0;
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        ++$files;
        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();
        preg_match('/^namespace\s+([^;{]+)\s*[;{]/m', $source, $ns);
        preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)/m', $source, $kind);
        expect($ns)->toHaveKey(1);
        expect($kind)->toHaveKey(1);
        $fqcn = trim($ns[1]) . '\\' . $kind[1];
        expect($reference)->toContain($fqcn);

        preg_match_all(
            '/^\s*(?:(?:final|abstract)\s+)?public\s+(?:static\s+)?function\s+&?\s*(\w+)\s*\(/m',
            $source,
            $public,
        );
        $methods += count($public[1]);
        preg_match_all('/^\s*public\s+(?:(?:readonly|static)\s+)*(?:[?\w\\\\|&]+\s+)?\$(\w+)\b/m', $source, $fields);
        $properties += count($fields[1]);
    }
    expect($files)->toBeGreaterThan(350)
        ->and($reference)->toContain('**' . $files . ' PHP types**')
        ->and($reference)->toContain('**' . $methods . ' public methods**')
        ->and($reference)->toContain('**' . $properties . ' public properties**');
});
