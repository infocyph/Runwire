<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics\Enum;

/**
 * Names protocol-specific counters and gauges exposed in runtime metrics.
 */
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
}
