<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use RuntimeException;

/**
 * Reports invalid HTTP/2 request or trailer header fields.
 */
final class HeaderValidationException extends RuntimeException {}
