<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

enum RuntimeCapability: string
{
    case CONCURRENT = 'concurrent';

    case HOST_NATIVE_COROUTINES = 'host_native_coroutines';

    case HOST_OWNS_EVENT_LOOP = 'host_owns_event_loop';

    case OWNS_EVENT_LOOP = 'owns_event_loop';

    case OWNS_LISTENER = 'owns_listener';

    case OWNS_WORKER_POOL = 'owns_worker_pool';

    case PERSISTENT = 'persistent';

    case RUNWIRE_COROUTINES = 'runwire_coroutines';

    case RUNWIRE_LOOP_AVAILABLE = 'runwire_loop_available';

    case SUPPORTS_GRACEFUL_RELOAD = 'supports_graceful_reload';

    case SUPPORTS_HTTP1 = 'supports_http1';

    case SUPPORTS_HTTP2 = 'supports_http2';

    case SUPPORTS_HTTP3 = 'supports_http3';

    case SUPPORTS_WORKER_RECYCLE = 'supports_worker_recycle';
}
