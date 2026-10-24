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
            . escapeshellarg($revision);
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        expect($status)->toBe(0, implode("\n", $output));

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
