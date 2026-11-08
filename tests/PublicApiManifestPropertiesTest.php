<?php

declare(strict_types=1);

it('captures declared and promoted public fields in a reproducible source-only manifest', function (): void {
    $root = sys_get_temp_dir() . '/runwire-api-property-' . bin2hex(random_bytes(6));
    expect(mkdir($root, 0700))->toBeTrue();
    $file = $root . '/Fixture.php';
    $manifest = $root . '/manifest.json';

    try {
        file_put_contents($file, <<<'PHP'
<?php
namespace Example;
final class Fixture
{
    public readonly string $label;
    private int $hidden;

    public function __construct(public string $name, private int $secret)
    {
        $this->label = $name;
    }
}
PHP);
        $tool = dirname(__DIR__) . '/tools/public-api-manifest.php';
        $revision = str_repeat('a', 40);
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($tool) . ' '
            . escapeshellarg($root) . ' '
            . escapeshellarg($manifest) . ' '
            . escapeshellarg($revision) . ' --with-properties';
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        expect($status)->toBe(0, implode("\n", $output));

        $wrapperCommand = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($tool) . ' '
            . escapeshellarg($root) . ' '
            . escapeshellarg('php://memory') . ' '
            . escapeshellarg($revision) . ' --with-properties';
        $wrapperMessages = [];
        $wrapperStatus = 0;
        exec($wrapperCommand . ' 2>&1', $wrapperMessages, $wrapperStatus);
        expect($wrapperStatus)->not->toBe(0)
            ->and(implode("\n", $wrapperMessages))->toContain('local, non-symlink file path');

        $json = file_get_contents($manifest);
        expect($json)->toBeString();
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $fields = $decoded['symbols']['Example\\Fixture']['publicProperties'];
        expect(array_keys($fields))->toBe(['label', 'name'])
            ->and($fields['label'])->toContain('public readonly string $label')
            ->and($fields['name'])->toContain('public string $name')
            ->and($fields)->not->toHaveKey('secret')
            ->and($fields)->not->toHaveKey('hidden');
    } finally {
        if (is_file($manifest)) {
            unlink($manifest);
        }
        if (is_file($file)) {
            unlink($file);
        }
        rmdir($root);
    }
});

it('rejects a symlinked PHP declaration that targets a file outside the requested source directory', function (): void {
    $base = sys_get_temp_dir() . '/runwire-api-link-' . bin2hex(random_bytes(6));
    $source = $base . '/src';
    expect(mkdir($source, 0700, true))->toBeTrue();
    $external = $base . '/Outside.php';
    $link = $source . '/Linked.php';
    $output = $base . '/manifest.json';

    try {
        file_put_contents($external, "<?php\nnamespace Example;\nfinal class Outside {}\n");
        expect(symlink($external, $link))->toBeTrue();
        $tool = dirname(__DIR__) . '/tools/public-api-manifest.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool)
            . ' ' . escapeshellarg($source)
            . ' ' . escapeshellarg($output)
            . ' ' . escapeshellarg(str_repeat('a', 40));
        $messages = [];
        $status = 0;
        exec($command . ' 2>&1', $messages, $status);
        expect($status)->not->toBe(0)
            ->and(implode("\n", $messages))->toContain('refuses symlinked or outside-root');
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
        if (is_file($external)) {
            unlink($external);
        }
        if (is_file($output)) {
            unlink($output);
        }
        rmdir($source);
        rmdir($base);
    }
});
