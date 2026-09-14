<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Exception;

use RuntimeException;

/**
 * Signals an operation attempted on a closed coroutine channel.
 */
final class ChannelClosedException extends RuntimeException {}
