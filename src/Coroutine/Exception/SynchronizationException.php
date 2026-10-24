<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

use RuntimeException;

/**
 * Base exception for coroutine synchronization failures.
 */
class SynchronizationException extends RuntimeException {}
