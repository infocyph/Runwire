<?php

declare(strict_types=1);

it('installs a 3.1 candidate as 3.1 in both independent Composer consumers', function (): void {
    $root = dirname(__DIR__) . '/.github/workflows/';
    $files = [
        $root . 'release-candidate.yml',
        $root . 'release-certification.yml',
    ];
    foreach ($files as $path) {
        $workflow = file_get_contents($path);
        expect($workflow)->toBeString()
            ->not->toContain('2.2.x-dev')
            ->toContain('"infocyph/runwire": "3.1.x-dev"')
            ->toContain('index("3.1.x-dev") != null')
            ->toContain('composer check-platform-reqs');
    }
});

it('keeps portable package runtime requirements free of hard optional extensions', function (): void {
    $root = dirname(__DIR__);
    $composerContent = file_get_contents($root . '/composer.json');
    if (!is_string($composerContent)) {
        throw new RuntimeException('Unable to read the package manifest.');
    }
    $package = json_decode(
        $composerContent,
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    \PHPUnit\Framework\Assert::assertIsArray($package);
    \PHPUnit\Framework\Assert::assertIsArray($package['autoload']);
    expect($package['name'])->toBe('infocyph/runwire')
        ->and($package['type'])->toBe('library')
        ->and($package['require'])->toHaveKeys(['php', 'php-64bit', 'ext-filter'])
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
        'benchmarks', 'build', 'coverage', 'docs', 'graphify-out', 'qa', 'tests', 'tools', 'var', 'vendor',
    ];
    expect(mkdir($directory, 0700))->toBeTrue();

    try {
        copy(dirname(__DIR__) . '/composer.json', $directory . '/composer.json');
        copy(dirname(__DIR__) . '/.gitattributes', $directory . '/.gitattributes');
        mkdir($directory . '/src');
        file_put_contents($directory . '/src/PackageFixture.php', '<?php declare(strict_types=1);');
        mkdir($directory . '/runtime');
        file_put_contents($directory . '/runtime/executor-worker.php', '<?php declare(strict_types=1);');
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
        \PHPUnit\Framework\Assert::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Composer archive failed: ' . (string) $stdout . (string) $stderr);
        }
        $archive = new PharData($directory . '/package/candidate.zip');
        expect(isset($archive['composer.json']))->toBeTrue()
            ->and(isset($archive['src/PackageFixture.php']))->toBeTrue()
            ->and(isset($archive['runtime/executor-worker.php']))->toBeTrue();
        foreach ($forbidden as $path) {
            expect(isset($archive[$path . '/local-only.fixture']))->toBeFalse();
        }
    } finally {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \PHPUnit\Framework\Assert::assertInstanceOf(SplFileInfo::class, $entry);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
});

it('keeps the mandatory executor worker in packaged runtime but excludes developer tools', function (): void {
    $root = dirname(__DIR__);
    $attrs = file_get_contents($root . '/.gitattributes');
    $launcher = file_get_contents($root . '/src/Executor/Internal/ExecutorIpcTrait.php');
    $worker = file_get_contents($root . '/runtime/executor-worker.php');

    expect($attrs)->toBeString()
        ->toContain('/tools export-ignore')
        ->and($launcher)->toBeString()
        ->toContain("'/runtime/executor-worker.php'")
        ->and($worker)->toBeString()
        ->toContain('@psalm-taint-escape file')
        ->toContain('@psalm-taint-escape ssrf')
        ->toContain('@psalm-taint-escape url_component')
        ->toContain('@psalm-taint-escape url_path');
});
