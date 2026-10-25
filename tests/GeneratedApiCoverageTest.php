<?php

declare(strict_types=1);

it('source-links every declared public PHP symbol into the generated API catalog', function (): void {
    $root = dirname(__DIR__);
    $output = [];
    $code = 0;
    exec('python3 ' . escapeshellarg($root . '/docs/build_api_reference.py') . ' 2>&1', $output, $code);
    expect($code)->toBe(0, implode("\n", $output));
    $index = file_get_contents($root . '/docs/_generated/public-api.inc');
    expect($index)->toBeString();
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );
    $covered = 0;
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();
        preg_match('/^namespace\s+([^;{]+)\s*[;{]/m', $source, $namespace);
        preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)/m', $source, $type);
        expect($namespace)->toHaveKey(1)->and($type)->toHaveKey(1);
        $fqcn = trim($namespace[1]) . '\\' . $type[1];
        $marker = '``' . $fqcn . '``';
        $offset = strpos($index, $marker);
        expect($offset)->not->toBeFalse("Missing public type {$fqcn}");
        $next = strpos($index, "\n* ", (int) $offset);
        $entry = substr($index, (int) $offset, $next === false ? null : $next - $offset);
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/src/')));
        expect($entry)->toContain($relative . '#L');
        preg_match_all('/^\s*(?:(?:final|abstract)\s+)?public\s+(?:static\s+)?function\s+&?\s*(\w+)\s*\(/m', $source, $methods);
        foreach ($methods[1] as $method) {
            expect($entry)->toContain($method . '(');
        }
        preg_match_all('/^\s*public\s+(?:(?:readonly|static)\s+)*(?:[?\w\\\\|&]+\s+)?\$(\w+)\b/m', $source, $properties);
        foreach ($properties[1] as $property) {
            expect($entry)->toContain('``' . $property . '``');
        }
        preg_match_all('/^\s*public\s+const\s+(?:\w+\s+)?([A-Za-z_]\w*)\s*=/m', $source, $constants);
        foreach ($constants[1] as $constant) {
            expect($entry)->toContain('``' . $constant . '``');
        }
        if (str_contains($entry, '(enum)')) {
            preg_match_all('/^\s*case\s+([A-Za-z_]\w*)\s*(?:[;=])/m', $source, $cases);
            foreach ($cases[1] as $case) {
                expect($entry)->toContain('``' . $case . '``');
            }
        }
        ++$covered;
    }
    expect($covered)->toBeGreaterThan(400);
    $exceptions = strstr($index, "Exception catalog\n");
    expect($exceptions)->toBeString()
        ->toContain('RuntimeException', 'Http2MultiplexConnection', 'Http/Client/Http2MultiplexConnection.php#L');
});
