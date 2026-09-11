<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Http\HeaderField;
use InvalidArgumentException;

final class Http1Syntax
{
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
        if (str_contains($matches[2], '#')) {
            throw new ParseFailure(400, 'HTTP request-target must not contain a fragment.');
        }

        return [$matches[1], $matches[2]];
    }

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
}
