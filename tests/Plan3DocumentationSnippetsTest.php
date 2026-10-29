<?php

declare(strict_types=1);

it('keeps every native RST PHP example syntactically parseable', function (): void {
    $files = glob(dirname(__DIR__) . '/docs/*.rst') ?: [];
    expect($files)->not->toBeEmpty();
    $examples = 0;

    foreach ($files as $file) {
        // Historical 2.0 RST retains its original examples as migration
        // context; runnable syntax/fixture certification applies to 3.0 only.
        if (preg_match('/(?:-2\.0|-2\.1\.2)\.rst$/', $file) === 1) {
            continue;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        expect($lines)->not->toBeEmpty();

        for ($index = 0, $count = count($lines); $index < $count; ++$index) {
            if (trim($lines[$index]) !== '.. code-block:: php') {
                continue;
            }

            $snippet = '';
            for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
                $line = $lines[$cursor];
                if ($line === '') {
                    $snippet .= "\n";
                    continue;
                }
                if (!str_starts_with($line, '   ')) {
                    break;
                }
                $snippet .= substr($line, 3) . "\n";
            }

            expect(trim($snippet))->not->toBe('');
            $source = str_starts_with(ltrim($snippet), '<?php') ? $snippet : "<?php\n" . $snippet;
            try {
                token_get_all($source, TOKEN_PARSE);
            } catch (ParseError $error) {
                throw new RuntimeException(
                    sprintf('Invalid PHP example %s:%d: %s', $file, $index + 1, $error->getMessage()),
                    previous: $error,
                );
            }

            ++$examples;
            $index = $cursor - 1;
        }
    }

    expect($examples)->toBeGreaterThanOrEqual(6);
});

it('resolves all documented runnable fixture and benchmark references', function (): void {
    $root = dirname(__DIR__);
    $files = glob($root . '/docs/*.rst') ?: [];
    expect($files)->not->toBeEmpty();
    $references = 0;

    foreach ($files as $file) {
        // Historical 2.0 RST retains its original examples as migration
        // context; runnable syntax/fixture certification applies to 3.0 only.
        if (preg_match('/(?:-2\.0|-2\.1\.2)\.rst$/', $file) === 1) {
            continue;
        }
        $source = file_get_contents($file);
        if (!is_string($source)) {
            throw new RuntimeException('Unreadable RST source: ' . $file);
        }
        preg_match_all('~\b(?:tests|tools|benchmarks)/[A-Za-z0-9_./-]+\.(?:php|py)\b~', $source, $matches);

        foreach (array_unique($matches[0]) as $relative) {
            expect(is_file($root . '/' . $relative))
                ->toBeTrue(sprintf('Broken documentation source reference %s in %s', $relative, $file));
            ++$references;
        }
    }

    expect($references)->toBeGreaterThanOrEqual(20);
});
