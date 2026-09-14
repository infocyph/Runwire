<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Internal\HeaderValidationException as CommonHeaderValidationException;
use Infocyph\Runwire\Http\Internal\RequestHeaderValidator as CommonRequestHeaderValidator;

/**
 * Applies HTTP/2-specific error mapping around common request-header validation.
 */
final readonly class RequestHeaderValidator
{
    private CommonRequestHeaderValidator $validator;

    /**
     * Create an HTTP/2 request-header validator.
     */
    public function __construct()
    {
        $this->validator = new CommonRequestHeaderValidator('HTTP/2');
    }

    /** @param list<array{0: string, 1: string}> $fields */
    public function request(array $fields): ValidatedRequestHead
    {
        try {
            $head = $this->validator->request($fields);
        } catch (CommonHeaderValidationException $exception) {
            throw new HeaderValidationException($exception->getMessage(), previous: $exception);
        }

        return new ValidatedRequestHead(
            $head->method,
            $head->target,
            $head->headers,
            $head->contentLength,
        );
    }

    /** @param list<array{0: string, 1: string}> $fields */
    public function trailers(array $fields): Headers
    {
        try {
            return $this->validator->trailers($fields);
        } catch (CommonHeaderValidationException $exception) {
            throw new HeaderValidationException($exception->getMessage(), previous: $exception);
        }
    }
}
