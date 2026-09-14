<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

/**
 * Reports invalid normalized HTTP request header or pseudo-header state.
 */
final class HeaderValidationException extends \RuntimeException {}
