<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Support;

/**
 * Mutable persistent state fixture used to prove request isolation.
 */
final class PersistentFixtureState
{
    public static ?string $staticRequest = null;

    public ?string $singleton = null;
    public ?string $container = null;
    public ?string $auth = null;
    public string $locale = 'en';
    public ?string $trace = null;
    public bool $transactionOpen = false;
    public ?string $taskLocal = null;

    public function clean(): bool
    {
        return self::$staticRequest === null
            && $this->singleton === null
            && $this->container === null
            && $this->auth === null
            && $this->locale === 'en'
            && $this->trace === null
            && !$this->transactionOpen
            && $this->taskLocal === null;
    }

    public function dirty(string $request): void
    {
        self::$staticRequest = $request;
        $this->singleton = $request;
        $this->container = $request;
        $this->auth = 'user:' . $request;
        $this->locale = 'bn';
        $this->trace = 'trace:' . $request;
        $this->transactionOpen = true;
        $this->taskLocal = 'task:' . $request;
    }

    public function reset(): void
    {
        self::$staticRequest = null;
        $this->singleton = null;
        $this->container = null;
        $this->auth = null;
        $this->locale = 'en';
        $this->trace = null;
        $this->transactionOpen = false;
        $this->taskLocal = null;
    }
}
