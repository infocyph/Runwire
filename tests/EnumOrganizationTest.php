<?php

declare(strict_types=1);

test('all source enums live in domain-local Enum namespaces', function (): void {
    $sourceRoot = dirname(__DIR__) . '/src';
    $violations = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $code = file_get_contents($path);
        if (!is_string($code)) {
            $violations[] = sprintf('%s: unreadable source file', $path);
            continue;
        }

        $containsEnum = false;
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && $token[0] === T_ENUM) {
                $containsEnum = true;
                break;
            }
        }
        if (!$containsEnum) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($path, strlen($sourceRoot) + 1));
        if (!str_contains($relative, '/Enum/')) {
            $violations[] = sprintf('%s: enum file is not inside a domain-local Enum directory', $relative);
        }

        if (preg_match('/^namespace\s+([^;]+);/m', $code, $namespaceMatch) !== 1) {
            $violations[] = sprintf('%s: enum namespace is missing', $relative);
            continue;
        }
        if (preg_match('/\benum\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $enumMatch) !== 1) {
            $violations[] = sprintf('%s: enum declaration could not be parsed', $relative);
            continue;
        }

        $expectedNamespace = 'Infocyph\\Runwire\\' . str_replace('/', '\\', dirname($relative));
        if ($namespaceMatch[1] !== $expectedNamespace) {
            $violations[] = sprintf(
                '%s: expected namespace %s, found %s',
                $relative,
                $expectedNamespace,
                $namespaceMatch[1],
            );
        }
        if (basename($relative) !== $enumMatch[1] . '.php') {
            $violations[] = sprintf('%s: filename must match enum %s', $relative, $enumMatch[1]);
        }
    }

    expect($violations)->toBe([]);
});
