<?php

declare(strict_types=1);

it('installs a 3.0 candidate as 3.0 in both independent Composer consumers', function (): void {
    $root = dirname(__DIR__) . '/.github/workflows/';
    $files = [
        $root . 'release-candidate.yml',
        $root . 'release-certification.yml',
    ];
    foreach ($files as $path) {
        $workflow = file_get_contents($path);
        expect($workflow)->toBeString()
            ->not->toContain('2.2.x-dev')
            ->toContain('"infocyph/runwire": "3.0.x-dev"')
            ->toContain('index("3.0.x-dev") != null')
            ->toContain('composer check-platform-reqs');
    }
});

it('keeps portable package runtime requirements free of hard optional extensions', function (): void {
    $root = dirname(__DIR__);
    $package = json_decode(
        file_get_contents($root . '/composer.json'),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    expect($package['name'])->toBe('infocyph/runwire')
        ->and($package['type'])->toBe('library')
        ->and($package['require'])->toHaveKeys(['php', 'php-64bit'])
        ->and($package['require'])->not->toHaveKey('infocyph/foundation')
        ->and($package['require'])->not->toHaveKey('ext-event')
        ->and($package['require'])->not->toHaveKey('ext-pcntl')
        ->and($package['require'])->not->toHaveKey('ext-quic')
        ->and($package['autoload']['psr-4'])->toHaveKey('Infocyph\\Runwire\\')
        ->and($package)->not->toHaveKey('archive');
});

it('excludes local data and development payloads from an actual Composer archive', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-package-' . bin2hex(random_bytes(8));
    $forbidden = [
        '.aws', '.cache', '.ci', '.agents', '.codex', '.github', '.idea',
        '.phpunit.cache', '.psalm-cache', '.vscode', '.windsurf',
        'benchmarks', 'build', 'coverage', 'docs', 'graphify-out', 'qa', 'tests', 'var', 'vendor',
    ];
    expect(mkdir($directory, 0700))->toBeTrue();
    try {
        copy(dirname(__DIR__) . '/composer.json', $directory . '/composer.json');
        copy(dirname(__DIR__) . '/.gitattributes', $directory . '/.gitattributes');
        mkdir($directory . '/src');
        file_put_contents($directory . '/src/PackageFixture.php', '<?php declare(strict_types=1);');
        foreach ($forbidden as $path) {
            mkdir($directory . '/' . $path);
            file_put_contents($directory . '/' . $path . '/local-only.fixture', 'must not ship');
        }
        $process = proc_open(
            ['composer', '--no-plugins', '--no-scripts', 'archive', '--format=zip', '--dir=package', '--file=candidate'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
        );
        expect(is_resource($process))->toBeTrue();
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process), (string) $stdout . (string) $stderr)->toBe(0);
        $archive = new PharData($directory . '/package/candidate.zip');
        expect(isset($archive['composer.json']))->toBeTrue()
            ->and(isset($archive['src/PackageFixture.php']))->toBeTrue();
        foreach ($forbidden as $path) {
            expect(isset($archive[$path . '/local-only.fixture']), $path)->toBeFalse();
        }
    } finally {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
});
