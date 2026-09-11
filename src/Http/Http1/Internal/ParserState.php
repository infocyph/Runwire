<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

enum ParserState: string
{
    case CHUNK_CRLF = 'chunk_crlf';

    case CHUNK_DATA = 'chunk_data';

    case CHUNK_SIZE = 'chunk_size';

    case FIXED_BODY = 'fixed_body';

    case HEADERS = 'headers';

    case REQUEST_LINE = 'request_line';

    case TRAILERS = 'trailers';

    case WAIT_RESPONSE = 'wait_response';
}
