<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Enum;

/**
 * Identifies the HTTP protocol version negotiated for a request or connection.
 */
enum ProtocolVersion: string
{
    case HTTP_1_1 = '1.1';

    case HTTP_2 = '2';

    case HTTP_3 = '3';
}
