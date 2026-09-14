<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Exception;

use RuntimeException;

/**
 * Reports supervisor lifecycle or worker-management failures.
 */
final class SupervisorException extends RuntimeException {}
