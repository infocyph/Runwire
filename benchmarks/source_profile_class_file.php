<?php

declare(strict_types=1);

/**
 * Resolve a profiled class strictly inside its canonical source checkout.
 * Source selection is an explicit developer CLI operation; class names cannot
 * redirect the autoloader through traversal or symlinked paths.
 *
 * @return non-empty-string|null
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 */
function runwireSourceProfileClassFile(string $source, string $class): ?string
{
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        return null;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    if (preg_match('#\A[A-Za-z_][A-Za-z0-9_]*(?:/[A-Za-z_][A-Za-z0-9_]*)*\z#D', $relative) !== 1) {
        throw new InvalidArgumentException('Profile class name has invalid path segments.');
    }

    $candidate = $source . '/' . $relative . '.php';
    $file = realpath($candidate);
    if ($file === false) {
        return null;
    }

    if (!is_file($file) || !str_starts_with($file, rtrim($source, '/') . '/')) {
        throw new RuntimeException('Profile class file escapes its source checkout.');
    }

    return $file;
}
