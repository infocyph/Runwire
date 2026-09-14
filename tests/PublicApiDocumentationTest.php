<?php

declare(strict_types=1);

it('documents source types and public methods', function (): void {
    $sourceRoot = realpath(dirname(__DIR__) . '/src');

    if ($sourceRoot === false) {
        throw new RuntimeException('Unable to resolve source directory.');
    }

    $violations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
    );

    $hasDocCommentBefore = static function (array $tokens, int $index): bool {
        $attributeDepth = 0;

        for (--$index; $index >= 0; --$index) {
            $token = $tokens[$index];

            if ($token === ']') {
                ++$attributeDepth;

                continue;
            }
            if ($attributeDepth > 0) {
                if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                    --$attributeDepth;
                }

                continue;
            }
            if (is_string($token)) {
                if ($token === '&') {
                    continue;
                }

                return false;
            }

            if ($token[0] === T_DOC_COMMENT) {
                return true;
            }
            if (in_array($token[0], [
                T_WHITESPACE,
                T_COMMENT,
                T_PUBLIC,
                T_PROTECTED,
                T_PRIVATE,
                T_STATIC,
                T_FINAL,
                T_ABSTRACT,
                T_READONLY,
            ], true)) {
                continue;
            }

            return false;
        }

        return false;
    };

    $previousMeaningfulToken = static function (array $tokens, int $index): array|string|null {
        for (--$index; $index >= 0; --$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    };

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
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceRoot) + 1));
        $braceDepth = 0;
        $pendingType = false;
        $typeBraceDepths = [];

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token === '{') {
                ++$braceDepth;
                if ($pendingType) {
                    $typeBraceDepths[] = $braceDepth;
                    $pendingType = false;
                }

                continue;
            }
            if ($token === '}') {
                if ($typeBraceDepths !== [] && end($typeBraceDepths) === $braceDepth) {
                    array_pop($typeBraceDepths);
                }
                --$braceDepth;

                continue;
            }
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $previous = $previousMeaningfulToken($tokens, $index);
                if (
                    $token[0] === T_CLASS
                    && is_array($previous)
                    && in_array($previous[0], [T_NEW, T_DOUBLE_COLON], true)
                ) {
                    continue;
                }

                if (!$hasDocCommentBefore($tokens, $index)) {
                    $violations[] = sprintf('%s:%d type declaration is missing a docblock.', $relative, $token[2]);
                }
                $pendingType = true;

                continue;
            }

            if ($token[0] !== T_FUNCTION || $typeBraceDepths === []) {
                continue;
            }

            $isNonPublic = false;
            for ($modifierIndex = $index - 1; $modifierIndex >= 0; --$modifierIndex) {
                $modifier = $tokens[$modifierIndex];
                if (is_string($modifier)) {
                    if (in_array($modifier, [';', '{', '}'], true)) {
                        break;
                    }

                    continue;
                }
                if (in_array($modifier[0], [T_PRIVATE, T_PROTECTED], true)) {
                    $isNonPublic = true;
                    break;
                }
                if (in_array($modifier[0], [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    break;
                }
            }

            if ($isNonPublic || $hasDocCommentBefore($tokens, $index)) {
                continue;
            }

            $methodName = 'anonymous';
            for ($nameIndex = $index + 1; $nameIndex < $count; ++$nameIndex) {
                $nameToken = $tokens[$nameIndex];
                if (is_array($nameToken) && $nameToken[0] === T_STRING) {
                    $methodName = $nameToken[1];
                    break;
                }
                if ($nameToken === '(') {
                    break;
                }
            }

            if ($methodName !== 'anonymous') {
                $violations[] = sprintf('%s:%d public method %s() is missing a docblock.', $relative, $token[2], $methodName);
            }
        }
    }

    expect($violations)->toBe([]);
});
