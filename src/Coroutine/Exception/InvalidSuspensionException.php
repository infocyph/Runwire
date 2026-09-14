<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

use RuntimeException;

/**
 * Indicates that a coroutine suspended outside a supported scheduler primitive.
 */
final class InvalidSuspensionException extends RuntimeException {}
