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
        ->and($package['archive']['exclude'])->toContain('/vendor', '/tests', '/benchmarks');
});
