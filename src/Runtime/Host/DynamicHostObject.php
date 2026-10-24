<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Closure;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use ReflectionProperty;

/**
 * Provides validated dynamic access to host-runtime object properties and methods.
 */
final class DynamicHostObject
{
    /** @return array<string, mixed> */
    public static function arrayProperty(object $object, string $property): array
    {
        if (!property_exists($object, $property)) {
            return [];
        }

        $value = new ReflectionProperty($object, $property)->getValue($object);
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    /**
     * Returns a callable host method or fails when the method is unavailable.
     */
    public static function method(object $object, string $method): Closure
    {
        $callable = [$object, $method];
        if (!is_callable($callable)) {
            throw new RuntimeUnavailableException(sprintf(
                'Host object %s does not provide callable method %s().',
                $object::class,
                $method,
            ));
        }

        return Closure::fromCallable($callable);
    }
}
