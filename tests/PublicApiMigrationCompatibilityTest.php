<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Internal\ConnectionCloseObserverTrait;
use Infocyph\Runwire\Network\Internal\ConnectionLifecycleOperationsTrait;
use Infocyph\Runwire\Network\WriteResult;

it('keeps inherited published 2.1.2 Connection callbacks and write as public methods after trait moves', function (): void {
    $connection = new ReflectionClass(Connection::class);
    $close = $connection->getMethod('onClose');
    $write = $connection->getMethod('write');

    expect($connection->hasMethod('onClose'))->toBeTrue()
        ->and($connection->hasMethod('write'))->toBeTrue()
        ->and($close->isPublic())->toBeTrue()
        ->and($write->isPublic())->toBeTrue()
        ->and($close->getNumberOfRequiredParameters())->toBe(1)
        ->and($write->getNumberOfRequiredParameters())->toBe(1)
        ->and($close->getParameters()[0]->getType()?->getName())->toBe('callable')
        ->and($write->getParameters()[0]->getType()?->getName())->toBe('string')
        ->and($close->getReturnType()?->getName())->toBe('self')
        ->and($write->getReturnType()?->getName())->toBe(WriteResult::class)
        ->and($connection->getTraitNames())->toContain(
            ConnectionCloseObserverTrait::class,
            ConnectionLifecycleOperationsTrait::class,
        );
});

it('pins every reviewed source-only migration anomaly to approved immutable fingerprints', function (): void {
    $path = dirname(__DIR__) . '/docs/plans/evidence/runwire-3.0/u16-reviewed-api-delta.json';
    $json = file_get_contents($path);
    expect($json)->toBeString();
    $policy = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    expect($policy['schemaVersion'])->toBe(1)
        ->and($policy['publishedRevision'])->toBe('7e9bf20ad0737a187dfed77b723791b141b36378')
        ->and($policy['sourceMethodRelocations'])->toHaveCount(2)
        ->and($policy['reviewedSignatureChanges'])->toHaveCount(6);
    foreach ($policy['reviewedSignatureChanges'] as $change) {
        expect($change['symbol'])->toBeString()
            ->and($change['beforeSha256'])->toMatch('/^[a-f0-9]{64}$/')
            ->and($change['afterSha256'])->toMatch('/^[a-f0-9]{64}$/')
            ->and($change['beforeSha256'])->not->toBe($change['afterSha256']);
    }
});
