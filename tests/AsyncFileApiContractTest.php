<?php

declare(strict_types=1);

use Infocyph\Runwire\Filesystem\AsyncFileHandleInterface;
use Infocyph\Runwire\Filesystem\AsyncFileOperationsInterface;
use Infocyph\Runwire\Filesystem\FileAccessMode;

it('declares portable asynchronous file operations and explicit open mode permissions', function (): void {
    expect(FileAccessMode::READ->canRead())->toBeTrue()
        ->and(FileAccessMode::READ->canWrite())->toBeFalse()
        ->and(FileAccessMode::WRITE->canRead())->toBeFalse()
        ->and(FileAccessMode::WRITE->canWrite())->toBeTrue()
        ->and(FileAccessMode::READ_WRITE->canRead())->toBeTrue()
        ->and(FileAccessMode::READ_WRITE->canWrite())->toBeTrue();

    $fileOperations = new ReflectionClass(AsyncFileOperationsInterface::class);
    expect($fileOperations->isInterface())->toBeTrue();
    foreach (['open', 'read', 'write', 'stat', 'rename', 'unlink', 'cancel'] as $method) {
        expect($fileOperations->hasMethod($method))->toBeTrue();
    }

    $open = $fileOperations->getMethod('open');
    $returnType = $open->getReturnType();
    $modeType = $open->getParameters()[1]->getType();
    expect($returnType)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($modeType)->toBeInstanceOf(ReflectionNamedType::class);
    expect($returnType->getName())->toBe('int')
        ->and($modeType->getName())->toBe(FileAccessMode::class);

    $handle = new ReflectionClass(AsyncFileHandleInterface::class);
    expect($handle->isInterface())->toBeTrue();
    foreach (['read', 'write', 'stat', 'close', 'mode'] as $method) {
        expect($handle->hasMethod($method))->toBeTrue();
    }
});
