<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;
use InvalidArgumentException;

/**
 * @internal Builds bounded HTTP/3 request HEADERS and decodes response fields.
 *
 * Uses static QPACK only until the QUIC client owns encoder and decoder
 * instruction streams; never advertises dynamic references it cannot service.
 */
final class Http3ClientWireCodec
{
    private readonly Decoder $decoder;

    private readonly Encoder $encoder;

    /** Restrict both directions to unblocked, finite field sections. */
    public function __construct(int $maxFieldSectionBytes = 65_536, int $maxHeaderFields = 128)
    {
        $this->encoder = new Encoder(0, 0, $maxFieldSectionBytes);
        $this->decoder = new Decoder(0, 0, $maxFieldSectionBytes, $maxHeaderFields);
    }

    /**
     * Decode an HTTP/3 response HEADERS payload without allowing QPACK blocking.
     *
     * @return array{status:int, headers:Headers}
     */
    public function decodeResponseHeaders(string $payload, int $streamId): array
    {
        $decoded = $this->decoder->decode($payload, $streamId);
        if ($decoded === null) {
            throw new Http3Exception(ErrorCode::QPACK_DECOMPRESSION_FAILED, 'Blocking HTTP/3 response QPACK is unsupported.');
        }

        $status = null;
        $fields = [];
        foreach ($decoded->fields as [$name, $value]) {
            if ($name === ':status' && $status === null && $fields === []
                && preg_match('/^[1-5][0-9]{2}$/D', $value) === 1) {
                $status = (int) $value;

                continue;
            }
            if ($name === ':status' || str_starts_with($name, ':') || preg_match('/[A-Z]/', $name) === 1) {
                throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Invalid HTTP/3 response pseudo-header ordering.');
            }

            try {
                $fields[] = new HeaderField($name, $value);
            } catch (InvalidArgumentException $error) {
                throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Invalid HTTP/3 response header field.', $error);
            }
        }
        if ($status === null) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'HTTP/3 response has no valid :status pseudo-header.');
        }

        return ['status' => $status, 'headers' => new Headers($fields)];
    }

    /** Serialize one bodyless HTTPS request for an already verified QUIC+h3 stream. */
    public function encodeRequestHeaders(ClientRequest $request, int $streamId): string
    {
        $request->protocol->negotiate('h3', true, true);
        if ($request->body !== null) {
            throw new RuntimeUnavailableException('HTTP/3 client streaming body support is not yet implemented.');
        }

        $parts = parse_url($request->uri);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)
            || ($parts['scheme'] ?? null) !== 'https') {
            throw new InvalidArgumentException('HTTP/3 requires a verified HTTPS origin.');
        }
        $target = ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if ($target === '' || preg_match('/[\x00-\x20\x7f]/', $target) === 1) {
            throw new InvalidArgumentException('Invalid HTTP/3 request target.');
        }
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $fields = [
            [':method', $request->method],
            [':scheme', 'https'],
            [':authority', $authority],
            [':path', $target],
        ];
        foreach ($request->headers->fields() as $header) {
            $name = $header->name;
            if (in_array($name, ['host', 'connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade'], true)
                || ($name === 'te' && strtolower(trim($header->value)) !== 'trailers')
                || ($name === 'content-length' && $header->value !== '0')) {
                throw new InvalidArgumentException('HTTP/3 client request includes a prohibited hop-by-hop or body header.');
            }
            $fields[] = [$name, $header->value];
        }

        $encoded = $this->encoder->encode($fields, $streamId);
        if ($encoded->requiredInsertCount !== 0 || $encoded->blocking) {
            throw new Http3Exception(ErrorCode::INTERNAL_ERROR, 'Static HTTP/3 QPACK must never block.');
        }

        return (new Frame(FrameType::HEADERS->value, $encoded->block))->encode();
    }
}
