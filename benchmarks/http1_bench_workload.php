<?php

declare(strict_types=1);

/** The same bounded response payload is used by HTTP/1 native servers and client. */
function http1BenchResponseBody(): string
{
    $raw = getenv('RUNWIRE_BENCH_RESPONSE_BYTES');
    if ($raw === false || $raw === '') {
        return 'ok';
    }
    if (!ctype_digit($raw) || (int) $raw < 2 || (int) $raw > 65_536) {
        throw new InvalidArgumentException('H1 measured response size must be 2–65536 bytes.');
    }

    return (int) $raw === 2 ? 'ok' : str_repeat('x', (int) $raw);
}

/** Keep the close/reuse experimental dimension explicit. */
function http1BenchConnectionMode(): string
{
    $value = getenv('RUNWIRE_BENCH_CONNECTION_MODE');
    if ($value === false || $value === '') {
        return 'keep-alive';
    }
    if ($value !== 'keep-alive' && $value !== 'close') {
        throw new InvalidArgumentException('H1 benchmark connection mode must be keep-alive or close.');
    }

    return $value;
}

/** Generate the exact same H1 wire request across all matrix populations. */
function http1BenchRequest(): string
{
    return "GET /benchmark HTTP/1.1\r\nHost: localhost\r\nConnection: "
        . http1BenchConnectionMode() . "\r\n\r\n";
}
