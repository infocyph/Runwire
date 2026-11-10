<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/benchmarks/source_profile_class_file.php';

it('confines source microprofile class loading to its selected checkout', function (): void {
    $root = sys_get_temp_dir() . '/runwire-profile-src-' . bin2hex(random_bytes(6));
    $directory = $root . '/Http/Http2/Hpack';
    expect(mkdir($directory, 0700, true))->toBeTrue();
    $inside = $directory . '/Encoder.php';
    $linked = $directory . '/Decoder.php';
    $outside = tempnam(sys_get_temp_dir(), 'runwire-profile-outside-');
    \PHPUnit\Framework\Assert::assertIsString($outside);
    file_put_contents($inside, "<?php\n");
    expect(symlink($outside, $linked))->toBeTrue();

    try {
        expect(runwireSourceProfileClassFile($root, 'Infocyph\\Runwire\\Http\\Http2\\Hpack\\Encoder'))
            ->toBe($inside);
        expect(runwireSourceProfileClassFile($root, 'Other\\Namespace\\Encoder'))->toBeNull();
        expect(runwireSourceProfileClassFile($root, 'Infocyph\\Runwire\\Unknown'))->toBeNull();
        expect(fn(): ?string => runwireSourceProfileClassFile($root, 'Infocyph\\Runwire\\..\\Secret'))
            ->toThrow(InvalidArgumentException::class);
        expect(fn(): ?string => runwireSourceProfileClassFile($root, 'Infocyph\\Runwire\\Http\\Http2\\Hpack\\Decoder'))
            ->toThrow(RuntimeException::class);
    } finally {
        unlink($linked);
        unlink($inside);
        unlink($outside);
        rmdir($directory);
        rmdir(dirname($directory));
        rmdir(dirname($directory, 2));
        rmdir($root);
    }
});
