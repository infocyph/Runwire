<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

enum ParserState: string
{
    case REQUEST_LINE = 'request_line';
    case HEADERS = 'headers';
    case FIXED_BODY = 'fixed_body';
    case CHUNK_SIZE = 'chunk_size';
    case CHUNK_DATA = 'chunk_data';
    case CHUNK_CRLF = 'chunk_crlf';
    case TRAILERS = 'trailers';
    case WAIT_RESPONSE = 'wait_response';
}
