<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

use LogicException;

/**
 * Indicates an attempt to complete an already-settled future.
 */
final class FutureCompletedException extends LogicException {}
