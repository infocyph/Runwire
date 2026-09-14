<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Hpack;

use RuntimeException;

/**
 * Reports HPACK encoding and decoding failures.
 */
final class HpackException extends RuntimeException {}
