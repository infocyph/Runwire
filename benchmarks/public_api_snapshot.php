<?php

declare(strict_types=1);

// Developer-only snapshot: no reflection or source scanning occurs in production.
require dirname(__DIR__) . '/vendor/autoload.php';

$source = dirname(__DIR__) . '/src';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
$types = [];

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $relative = substr($file->getPathname(), strlen($source) + 1);
    $class = 'Infocyph\\Runwire\\' . str_replace(DIRECTORY_SEPARATOR, '\\', substr($relative, 0, -4));
    if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
        throw new RuntimeException(sprintf('Source type %s cannot be autoloaded.', $class));
    }
    $reflection = new ReflectionClass($class);
    $methods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $default = null;
            if ($parameter->isDefaultValueAvailable()) {
                if ($parameter->isDefaultValueConstant()) {
                    $default = $parameter->getDefaultValueConstantName();
                } else {
                    $value = $parameter->getDefaultValue();
                    $default = is_object($value) ? $value::class : $value;
                }
            }
            $parameters[] = [
                'name' => $parameter->getName(),
                'type' => (string) $parameter->getType(),
                'by_reference' => $parameter->isPassedByReference(),
                'variadic' => $parameter->isVariadic(),
                'has_default' => $parameter->isDefaultValueAvailable(),
                'default' => $default,
            ];
        }
        // PHP 8.5 reflection resolves return 'self' to a fully qualified class name.
        // Normalize the 8.4 representation so snapshots are comparable across PHP minors.
        $returnType = (string) $method->getReturnType();
        if ($returnType === 'self' || $returnType === '?self') {
            $returnType = ($returnType === '?self' ? '?' : '') . $class;
        }
        $methods[$method->getName()] = [
            'visibility' => $method->isPublic() ? 'public' : 'protected',
            'static' => $method->isStatic(),
            'returns' => $returnType,
            'parameters' => $parameters,
        ];
    }
    ksort($methods);
    $properties = [];
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
        if ($property->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        $properties[$property->getName()] = [
            'visibility' => $property->isPublic() ? 'public' : 'protected',
            'type' => (string) $property->getType(),
            'readonly' => $property->isReadOnly(),
        ];
    }
    ksort($properties);
    $types[$class] = [
        'kind' => $reflection->isInterface() ? 'interface' : ($reflection->isTrait() ? 'trait' : ($reflection->isEnum() ? 'enum' : 'class')),
        'final' => $reflection->isFinal(),
        'methods' => $methods,
        'properties' => $properties,
    ];
}
ksort($types);

fwrite(STDOUT, json_encode([
    'schema' => 1,
    'php_version' => PHP_VERSION,
    'type_count' => count($types),
    'types' => $types,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
