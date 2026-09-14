<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use InvalidArgumentException;

/**
 * Maps explicit host protocol metadata without silently upgrading unknown versions.
 */
final readonly class HostProtocolVersion
{
    /**
     * Map an explicit host protocol value to the supported HTTP version.
     */
    public static function from(string $protocol): ProtocolVersion
    {
        return match (strtoupper(trim($protocol))) {
            'HTTP/1.1' => ProtocolVersion::HTTP_1_1,
            'HTTP/2', 'HTTP/2.0' => ProtocolVersion::HTTP_2,
            'HTTP/3', 'HTTP/3.0' => ProtocolVersion::HTTP_3,
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported host HTTP protocol version "%s".',
                $protocol,
            )),
        };
    }
}
