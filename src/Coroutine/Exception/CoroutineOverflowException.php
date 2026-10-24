<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

use RuntimeException;

/**
 * Signals that a configured coroutine capacity limit has been exceeded.
 */
final class CoroutineOverflowException extends RuntimeException {}
