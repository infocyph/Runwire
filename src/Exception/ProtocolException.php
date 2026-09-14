<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Exception;

use RuntimeException;

/**
 * Base exception for wire-protocol parsing and framing failures.
 */
class ProtocolException extends RuntimeException {}
