<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Http\Headers;

final class RequestHeadValidator
{
    public function validate(Headers $headers, int $maxBodyBytes): RequestHead
    {
        $this->validateHost($headers);
        $contentLengths = $this->contentLengths($headers);
        $transfer = $this->tokens($headers->all('transfer-encoding'));

        if ($transfer !== [] && $contentLengths !== []) {
            throw new ParseFailure(400, 'Transfer-Encoding with Content-Length is rejected.');
        }

        if ($transfer !== [] && $transfer !== ['chunked']) {
            throw new ParseFailure(400, 'Only a single final chunked transfer coding is accepted.');
        }

        $length = $contentLengths[0] ?? 0;
        if ($length > $maxBodyBytes) {
            throw new ParseFailure(413, 'Request body exceeds configured limit.');
        }

        $expect = $this->tokens($headers->all('expect'));
        if ($expect !== [] && $expect !== ['100-continue']) {
            throw new ParseFailure(417, 'Unsupported Expect header.');
        }

        return new RequestHead(
            contentLength: $length,
            chunked: $transfer === ['chunked'],
            expectContinue: $expect === ['100-continue'],
            closeRequested: in_array('close', $this->tokens($headers->all('connection')), true),
        );
    }

    /** @return list<int> */
    private function contentLengths(Headers $headers): array
    {
        $lengths = [];
        foreach ($headers->all('content-length') as $value) {
            foreach (explode(',', $value) as $part) {
                $lengths[] = $this->parseContentLength(trim($part));
            }
        }

        if ($lengths !== [] && count(array_unique($lengths)) !== 1) {
            throw new ParseFailure(400, 'Conflicting Content-Length fields.');
        }

        return $lengths;
    }

    private function parseContentLength(string $value): int
    {
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new ParseFailure(400, 'Invalid Content-Length.');
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            throw new ParseFailure(413, 'Content-Length exceeds platform range.');
        }

        return (int) $normalized;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function tokens(array $values): array
    {
        $tokens = [];
        foreach ($values as $value) {
            foreach (explode(',', strtolower($value)) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    private function validateHost(Headers $headers): void
    {
        $host = $headers->all('host');
        if (count($host) !== 1 || trim($host[0]) === '') {
            throw new ParseFailure(400, 'HTTP/1.1 requires exactly one non-empty Host field.');
        }
    }
}
