<?php

declare(strict_types=1);

it('keeps indexed reStructuredText PHP examples syntactically valid', function (): void {
    $files = glob(dirname(__DIR__) . '/docs/*.rst');
    expect($files)->toBeArray()->not->toBeEmpty();

    $examples = 0;
    foreach ($files as $file) {
        // Historical Runwire 2.0 examples are archival, not runnable 3.0 samples.
        if (preg_match('/(?:-2\.0|-2\.1\.2)\.rst$/', $file) === 1) {
            continue;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        expect($lines)->toBeArray();

        $collecting = false;
        $body = [];
        foreach ([...$lines, 'END_OF_RST_EXAMPLE'] as $line) {
            if ($line === '.. code-block:: php') {
                $collecting = true;
                $body = [];
                continue;
            }
            if (!$collecting) {
                continue;
            }
            if ($line !== '' && !str_starts_with($line, '   ')) {
                $snippet = trim(implode("\n", $body));
                if ($snippet !== '') {
                    ++$examples;
                    $source = str_starts_with($snippet, '<?php')
                        ? $snippet
                        : "<?php\n" . $snippet;
                    try {
                        token_get_all($source, TOKEN_PARSE);
                    } catch (ParseError $error) {
                        throw new RuntimeException(basename($file) . ' has an invalid PHP example: ' . $error->getMessage(), 0, $error);
                    }
                }
                $collecting = false;
                continue;
            }

            $body[] = str_starts_with($line, '   ') ? substr($line, 3) : '';
        }
    }

    expect($examples)->toBeGreaterThan(0);
});

it('keeps runnable test and fixture references in the native docs resolvable', function (): void {
    $docs = glob(dirname(__DIR__) . '/docs/*.rst');
    expect($docs)->toBeArray()->not->toBeEmpty();

    $root = dirname(__DIR__);
    $checked = 0;
    foreach ($docs as $doc) {
        // Legacy 2.0 fixture paths are validated as archival sources separately.
        if (preg_match('/(?:-2\.0|-2\.1\.2)\.rst$/', $doc) === 1) {
            continue;
        }
        $text = file_get_contents($doc);
        expect($text)->toBeString();
        preg_match_all('~\b(?:tests|benchmarks)/[A-Za-z0-9_./-]+\.(?:php|py|sh)\b~', $text, $matches);
        foreach (array_unique($matches[0]) as $relativePath) {
            expect(is_file($root . '/' . $relativePath))
                ->toBeTrue(basename($doc) . ': missing source ' . $relativePath);
            ++$checked;
        }
    }

    expect($checked)->toBeGreaterThan(10);
});
