<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;

final class RequestHeaderValidator
{
    /** @var array<string, true> */
    private const array FORBIDDEN = [
        'connection' => true,
        'proxy-connection' => true,
        'keep-alive' => true,
        'transfer-encoding' => true,
        'upgrade' => true,
    ];

    /**
     * @param list<array{0: string, 1: string}> $fields
     */
    public function request(array $fields): ValidatedRequestHead
    {
        [$pseudo, $regular] = $this->split($fields, true);
        $method = $pseudo[':method'] ?? null;
        if ($method === null || $method === '') {
            throw new HeaderValidationException('HTTP/2 request is missing :method.');
        }

        $authority = $pseudo[':authority'] ?? null;
        if (strcasecmp($method, 'CONNECT') === 0) {
            if ($authority === null || $authority === '' || isset($pseudo[':scheme']) || isset($pseudo[':path'])) {
                throw new HeaderValidationException('HTTP/2 CONNECT requires :authority and forbids :scheme/:path.');
            }
            $target = $authority;
        } else {
            $scheme = $pseudo[':scheme'] ?? null;
            $path = $pseudo[':path'] ?? null;
            if ($scheme === null || $scheme === '' || $path === null || $path === '') {
                throw new HeaderValidationException('HTTP/2 request requires non-empty :scheme and :path.');
            }
            if (str_contains($path, '#')) {
                throw new HeaderValidationException('HTTP/2 :path must not contain a fragment.');
            }
            $target = $path;
        }

        $hostValues = $regular->all('host');
        if (count($hostValues) > 1) {
            throw new HeaderValidationException('HTTP/2 request must not contain multiple Host fields.');
        }
        if ($authority !== null && isset($hostValues[0]) && strcasecmp(trim($authority), trim($hostValues[0])) !== 0) {
            throw new HeaderValidationException('HTTP/2 :authority conflicts with Host.');
        }
        if ($authority !== null && $hostValues === []) {
            $regular = new Headers([new HeaderField('host', $authority), ...$regular->fields()]);
        }

        return new ValidatedRequestHead($method, $target, $regular, $this->contentLength($regular));
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     */
    public function trailers(array $fields): Headers
    {
        [, $regular] = $this->split($fields, false);

        foreach (['content-length', 'host'] as $forbidden) {
            if ($regular->has($forbidden)) {
                throw new HeaderValidationException(sprintf('HTTP/2 trailer field "%s" is not permitted.', $forbidden));
            }
        }

        return $regular;
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     * @return array{array<string, string>, Headers}
     */
    private function split(array $fields, bool $allowPseudo): array
    {
        $pseudo = [];
        $regular = [];
        $sawRegular = false;

        foreach ($fields as [$name, $value]) {
            if ($name === '' || strtolower($name) !== $name) {
                throw new HeaderValidationException('HTTP/2 header field names must be lowercase and non-empty.');
            }
            if (str_starts_with($name, ':')) {
                if (!$allowPseudo || $sawRegular) {
                    throw new HeaderValidationException('HTTP/2 pseudo-headers must precede regular headers and are forbidden in trailers.');
                }
                if (!in_array($name, [':method', ':scheme', ':authority', ':path'], true)) {
                    throw new HeaderValidationException(sprintf('Unsupported HTTP/2 pseudo-header "%s".', $name));
                }
                if (isset($pseudo[$name])) {
                    throw new HeaderValidationException(sprintf('Duplicate HTTP/2 pseudo-header "%s".', $name));
                }
                if ($this->invalidValue($value)) {
                    throw new HeaderValidationException('Invalid control character in HTTP/2 pseudo-header value.');
                }
                $pseudo[$name] = $value;
                continue;
            }

            $sawRegular = true;
            if (isset(self::FORBIDDEN[$name])) {
                throw new HeaderValidationException(sprintf('Connection-specific HTTP/2 field "%s" is forbidden.', $name));
            }
            if ($name === 'te' && strtolower(trim($value)) !== 'trailers') {
                throw new HeaderValidationException('HTTP/2 TE field may contain only "trailers".');
            }
            try {
                $regular[] = new HeaderField($name, $value);
            } catch (\InvalidArgumentException $exception) {
                throw new HeaderValidationException($exception->getMessage(), previous: $exception);
            }
        }

        return [$pseudo, new Headers($regular)];
    }

    private function contentLength(Headers $headers): ?int
    {
        $values = $headers->all('content-length');
        if ($values === []) {
            return null;
        }
        $parsed = array_map($this->parseLength(...), $values);
        if (count(array_unique($parsed, SORT_REGULAR)) > 1) {
            throw new HeaderValidationException('Conflicting HTTP/2 Content-Length fields are forbidden.');
        }
        return $parsed[0];
    }

    private function parseLength(string $value): int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new HeaderValidationException('Invalid HTTP/2 Content-Length.');
        }
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($max) || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            throw new HeaderValidationException('HTTP/2 Content-Length exceeds platform integer range.');
        }
        return (int) $normalized;
    }

    private function invalidValue(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1;
    }
}
