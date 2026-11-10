<?php

declare(strict_types=1);

/**
 * Check a class file before the U08 profilers execute it from a selected checkout.
 *
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 */
function runwireSourceProfileValidateClassFile(string $source, string $class, string $candidate): bool
{
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        throw new InvalidArgumentException('Profile class must belong to Runwire.');
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    if (preg_match('#\A[A-Za-z_][A-Za-z0-9_]*(?:/[A-Za-z_][A-Za-z0-9_]*)*\z#D', $relative) !== 1
        || $candidate !== $source . '/' . $relative . '.php') {
        throw new InvalidArgumentException('Profile class has an invalid source path.');
    }

    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved) || !str_starts_with($resolved, rtrim($source, '/') . '/')) {
        throw new RuntimeException('Profile class file escapes its source checkout or is not a regular file.');
    }

    return true;
}
