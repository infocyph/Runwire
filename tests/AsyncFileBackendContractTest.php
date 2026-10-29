<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;

it('requires the bounded off-loop executor instead of silently selecting synchronous or unqualified native file I/O', function (): void {
    $constructor = new ReflectionMethod(AsyncFileSystem::class, '__construct');
    $parameters = $constructor->getParameters();

    expect($parameters[0]->getName())->toBe('executor')
        ->and($parameters[0]->isOptional())->toBeFalse()
        ->and($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class)
        ->and($parameters[0]->getType()->getName())->toBe(ProcessExecutor::class);

    // This is a source-backed capability guard, not an assertion that any
    // native extension or fake file-async shim has been implemented.
    $source = file_get_contents(__DIR__ . '/../src/Filesystem/AsyncFileSystem.php');
    expect($source)->toBeString()
        ->toContain('$this->executor->submit(')
        ->not->toContain('file_get_contents(')
        ->not->toContain('file_put_contents(')
        ->not->toContain('fopen(')
        ->not->toContain('fwrite(')
        ->not->toContain('stream_get_contents(');

    $manual = file_get_contents(__DIR__ . '/../docs/async-file-io-3.0.rst');
    expect($manual)->toBeString()
        ->toContain('No native filesystem accelerator is currently qualified')
        ->toContain('only supported implementation');
});
