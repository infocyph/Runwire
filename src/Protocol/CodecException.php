<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Protocol;

use RuntimeException;

/**
 * Signals invalid or incomplete protocol frame encoding and decoding.
 */
final class CodecException extends RuntimeException {}
