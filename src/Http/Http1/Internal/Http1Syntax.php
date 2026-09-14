<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Internal\AuthorityValidator;
use InvalidArgumentException;

/**
 * Validates and parses HTTP/1.1 request-line and field syntax.
 */
final class Http1Syntax
{
    /**
     * Parse and validate one HTTP header field line.
     */
    public function headerField(string $line): HeaderField
    {
        if ($line[0] === ' ' || $line[0] === "\t") {
            throw new ParseFailure(400, 'Obsolete folded HTTP headers are rejected.');
        }
        $colon = strpos($line, ':');
        if ($colon === false || $colon === 0) {
            throw new ParseFailure(400, 'Malformed HTTP header line.');
        }

        return $this->field($line, $colon);
    }

    /** @return array{0: string, 1: string} */
    public function requestLine(string $line): array
    {
        if (preg_match("/^([!#$%&'*+.^_`|~0-9A-Za-z-]+) ([^\\x00-\\x20\\x7F]+) HTTP\\/1\\.1$/D", $line, $matches) !== 1) {
            throw new ParseFailure(400, 'Malformed HTTP/1.1 request line.');
        }
        $method = $matches[1];
        $target = $matches[2];
        if (str_contains($target, '#')) {
            throw new ParseFailure(400, 'HTTP request-target must not contain a fragment.');
        }

        $this->requestTarget($method, $target);

        return [$method, $target];
    }

    /**
     * Parse a trailer field while rejecting framing and routing fields.
     */
    public function trailerField(string $line): HeaderField
    {
        $field = $this->headerField($line);
        if (in_array($field->name, ['content-length', 'transfer-encoding', 'host'], true)) {
            throw new ParseFailure(400, 'Framing and routing fields are forbidden in trailers.');
        }

        return $field;
    }

    private function field(string $line, int $colon): HeaderField
    {
        try {
            return new HeaderField(
                substr($line, 0, $colon),
                trim(substr($line, $colon + 1), " \t"),
            );
        } catch (InvalidArgumentException $exception) {
            throw new ParseFailure(400, $exception->getMessage());
        }
    }

    private function requestTarget(string $method, string $target): void
    {
        if ($target === '*') {
            if (strcasecmp($method, 'OPTIONS') !== 0) {
                throw new ParseFailure(400, 'Asterisk-form request-target is permitted only for OPTIONS.');
            }

            return;
        }

        if (strcasecmp($method, 'CONNECT') === 0) {
            try {
                AuthorityValidator::normalize($target);
            } catch (InvalidArgumentException) {
                throw new ParseFailure(400, 'CONNECT requires a valid authority-form request-target.');
            }

            return;
        }

        if (str_starts_with($target, '/')) {
            return;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//D', $target) === 1) {
            return;
        }

        throw new ParseFailure(400, 'HTTP request-target form is invalid for the request method.');
    }
}
