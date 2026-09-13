<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics\Enum;

enum ProtocolMetric: string
{
    case HPACK_TABLE_BYTES = 'hpack_table_bytes';

    case HTTP1_CONNECTIONS_ACTIVE = 'http1_connections_active';

    case HTTP1_CONNECTIONS_TOTAL = 'http1_connections_total';

    case HTTP1_REQUESTS_TOTAL = 'http1_requests_total';

    case HTTP2_CONNECTIONS_ACTIVE = 'http2_connections_active';

    case HTTP2_CONNECTIONS_TOTAL = 'http2_connections_total';

    case HTTP2_RESETS_TOTAL = 'http2_resets_total';

    case HTTP2_STREAMS_ACTIVE = 'http2_streams_active';

    case HTTP2_STREAMS_TOTAL = 'http2_streams_total';

    case HTTP3_CONNECTIONS_ACTIVE = 'http3_connections_active';

    case HTTP3_CONNECTIONS_TOTAL = 'http3_connections_total';

    case HTTP3_RESETS_TOTAL = 'http3_resets_total';

    case HTTP3_STREAMS_ACTIVE = 'http3_streams_active';

    case HTTP3_STREAMS_TOTAL = 'http3_streams_total';

    case QPACK_BLOCKED_STREAMS = 'qpack_blocked_streams';

    case QPACK_TABLE_BYTES = 'qpack_table_bytes';
}
