<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tracing;

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\RequestContext;
use InvalidArgumentException;

/**
 * Bounded explicit W3C traceparent/tracestate propagation.
 *
 * No ambient process globals, implicit cross-tenant forwarding or SDK dependency.
 * Carry the RequestContext explicitly across Fiber/executor ownership boundaries.
 */
final readonly class TraceContext
{
    private const string ATTRIBUTE = 'runwire.trace_context';

    public function __construct(
        public string $traceId,
        public string $spanId,
        public int $flags = 0,
        public ?string $traceState = null,
    ) {
        if (preg_match('/^[0-9a-f]{32}$/D', $traceId) !== 1 || $traceId === str_repeat('0', 32)
            || preg_match('/^[0-9a-f]{16}$/D', $spanId) !== 1 || $spanId === str_repeat('0', 16)
            || $flags < 0 || $flags > 255) {
            throw new InvalidArgumentException('Invalid W3C trace identity or flags.');
        }
        if ($traceState !== null) {
            self::validateState($traceState);
        }
    }

    public static function fromHeaders(Headers $headers): ?self
    {
        $parents = $headers->all('traceparent');
        $states = $headers->all('tracestate');
        if ($parents === []) {
            return null;
        }
        if (count($parents) !== 1 || count($states) > 1
            || preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/D', $parents[0], $match) !== 1) {
            throw new InvalidArgumentException('Malformed or duplicate W3C traceparent header.');
        }

        return new self($match[1], $match[2], hexdec($match[3]), $states[0] ?? null);
    }

    /** Create an independent span while preserving the trace and bounded tracestate. */
    public function child(): self
    {
        return new self($this->traceId, bin2hex(random_bytes(8)), $this->flags, $this->traceState);
    }

    /** Produce new header fields, replacing any untrusted inbound trace context. */
    public function inject(Headers $headers): Headers
    {
        $fields = [];
        foreach ($headers->fields() as $field) {
            if ($field->name !== 'traceparent' && $field->name !== 'tracestate') {
                $fields[] = $field;
            }
        }
        $fields[] = new HeaderField('traceparent', '00-' . $this->traceId . '-' . $this->spanId
            . '-' . str_pad(dechex($this->flags), 2, '0', STR_PAD_LEFT));
        if ($this->traceState !== null) {
            $fields[] = new HeaderField('tracestate', $this->traceState);
        }

        return new Headers($fields);
    }

    /** Bind trace identity to an explicitly owned request, never a global Fiber slot. */
    public function bind(RequestContext $request): void
    {
        $request->setAttribute(self::ATTRIBUTE, $this);
    }

    public static function forRequest(RequestContext $request): ?self
    {
        $context = $request->attribute(self::ATTRIBUTE);

        return $context instanceof self ? $context : null;
    }

    private static function validateState(string $state): void
    {
        if ($state === '' || strlen($state) > 512) {
            throw new InvalidArgumentException('W3C tracestate must fit the 512-byte limit.');
        }
        $members = explode(',', $state);
        if (count($members) > 32) {
            throw new InvalidArgumentException('W3C tracestate has too many members.');
        }
        foreach ($members as $member) {
            if (preg_match('/^[a-z0-9][a-z0-9_\\-*/@]{0,255}=([\x20-\x2B\x2D-\x3C\x3E-\x7E]+)$/D', trim($member)) !== 1) {
                throw new InvalidArgumentException('W3C tracestate contains an invalid member.');
            }
        }
    }
}
