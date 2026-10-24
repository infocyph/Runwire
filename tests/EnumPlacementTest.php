<?php

declare(strict_types=1);

it('keeps enums in domain-local Enum directories with matching namespaces', function (): void {
    $sourceRoot = realpath(dirname(__DIR__) . '/src');

    if ($sourceRoot === false) {
        throw new RuntimeException('Unable to resolve source directory.');
    }

    $violations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read %s.', $file->getPathname()));
        }

        $tokens = token_get_all($contents);
        $isEnum = false;
        $namespace = '';

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];

            if (is_array($token) && $token[0] === T_ENUM) {
                $isEnum = true;
            }

            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            for (++$index; $index < $count; ++$index) {
                $namespaceToken = $tokens[$index];

                if ($namespaceToken === ';' || $namespaceToken === '{') {
                    break;
                }

                if (
                    is_array($namespaceToken)
                    && in_array($namespaceToken[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)
                ) {
                    $namespace .= $namespaceToken[1];
                }
            }
        }

        if (!$isEnum) {
            continue;
        }

        $relative = str_replace(
            '\\',
            '/',
            substr($file->getPathname(), strlen($sourceRoot) + 1),
        );
        $directory = str_replace('\\', '/', dirname($relative));
        $expectedNamespace = 'Infocyph\\Runwire\\' . str_replace('/', '\\', $directory);

        if (!str_contains('/' . $directory . '/', '/Enum/')) {
            $violations[] = sprintf('%s is not located in a domain-local Enum directory.', $relative);
        }

        if ($namespace !== $expectedNamespace) {
            $violations[] = sprintf(
                '%s declares namespace %s; expected %s.',
                $relative,
                $namespace,
                $expectedNamespace,
            );
        }
    }

    expect($violations)->toBe([]);
});
