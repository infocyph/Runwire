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
final readonly class Http3ClientWireCodec
{
    private Decoder $decoder;

    private Encoder $encoder;

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
            if ($name === ':status') {
                if ($status !== null || $fields !== [] || preg_match('/^[1-5][0-9]{2}$/D', $value) !== 1) {
                    throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Invalid HTTP/3 response pseudo-header ordering.');
                }
                $status = (int) $value;

                continue;
            }
            $fields[] = self::responseField($name, $value);
        }

        if ($status === null) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'HTTP/3 response has no valid :status pseudo-header.');
        }

        return ['status' => $status, 'headers' => new Headers($fields)];
    }

    /** Serialize HTTPS request headers for an already verified QUIC+h3 stream. */
    public function encodeRequestHeaders(ClientRequest $request, int $streamId): string
    {
        $request->protocol->negotiate('h3', true, true);
        if (strtoupper($request->method) === 'CONNECT') {
            throw new RuntimeUnavailableException('HTTP/3 CONNECT needs a separately authenticated tunnel protocol.');
        }
        $fields = self::requestFields($request);

        $encoded = $this->encoder->encode($fields, $streamId);
        if ($encoded->requiredInsertCount !== 0 || $encoded->blocking) {
            throw new Http3Exception(ErrorCode::INTERNAL_ERROR, 'Static HTTP/3 QPACK must never block.');
        }

        return new Frame(FrameType::HEADERS->value, $encoded->block)->encode();
    }

    /** Encode the final bounded HTTP/3 trailer section without dynamic QPACK. */
    public function encodeRequestTrailers(Headers $trailers, int $streamId): string
    {
        $fields = [];
        foreach ($trailers->fields() as $field) {
            if (str_starts_with($field->name, ':')
                || in_array($field->name, ['content-length', 'host', 'te', 'trailer'], true)) {
                throw new InvalidArgumentException('HTTP/3 upload trailers contain prohibited routing or framing fields.');
            }
            self::assertRequestHeader($field->name, $field->value, true);
            $fields[] = [$field->name, $field->value];
        }
        if ($fields === []) {
            return '';
        }

        $encoded = $this->encoder->encode($fields, $streamId);
        if ($encoded->requiredInsertCount !== 0 || $encoded->blocking) {
            throw new Http3Exception(ErrorCode::INTERNAL_ERROR, 'HTTP/3 upload trailers must never block.');
        }

        return new Frame(FrameType::HEADERS->value, $encoded->block)->encode();
    }

    private static function assertRequestHeader(string $name, string $value, bool $hasBody): void
    {
        if (in_array($name, ['host', 'connection', 'keep-alive', 'proxy-connection', 'proxy-authorization', 'transfer-encoding', 'upgrade'], true)
            || ($name === 'te' && strtolower(trim($value)) !== 'trailers')
            || ($name === 'content-length' && ($hasBody || $value !== '0'))) {
            throw new InvalidArgumentException('HTTP/3 client request includes a prohibited hop-by-hop or body header.');
        }
    }

    /** @return list<array{0:string,1:string}> */
    private static function requestFields(ClientRequest $request): array
    {
        [$authority, $target] = self::validatedOrigin($request->uri);
        $fields = [
            [':method', $request->method],
            [':scheme', 'https'],
            [':authority', $authority],
            [':path', $target],
        ];
        foreach ($request->headers->fields() as $header) {
            self::assertRequestHeader($header->name, $header->value, $request->body !== null);
            $fields[] = [$header->name, $header->value];
        }

        return $fields;
    }

    private static function responseField(string $name, string $value): HeaderField
    {
        if (str_starts_with($name, ':') || preg_match('/[A-Z]/', $name) === 1) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Invalid HTTP/3 response pseudo-header ordering.');
        }
        if (in_array($name, ['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade'], true)) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'HTTP/3 response included a prohibited hop-by-hop field.');
        }

        try {
            return new HeaderField($name, $value);
        } catch (InvalidArgumentException $error) {
            throw new Http3Exception(ErrorCode::MESSAGE_ERROR, 'Invalid HTTP/3 response header field.', $error);
        }
    }

    /** @return array{0:string,1:string} */
    private static function validatedOrigin(string $uri): array
    {
        $parts = parse_url($uri);
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

        return [$authority, $target];
    }
}
