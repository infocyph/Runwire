<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

enum ProtocolVersion: string
{
    case HTTP_1_1 = '1.1';

    case HTTP_2 = '2';

    case HTTP_3 = '3';
}
