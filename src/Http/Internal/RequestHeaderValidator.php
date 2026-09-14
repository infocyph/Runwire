<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use InvalidArgumentException;

/**
 * Validates HTTP/2 and HTTP/3 request field sections into normalized request metadata.
 */
final readonly class RequestHeaderValidator
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
     * Create a validator labeled for the supplied HTTP protocol.
     */
    public function __construct(private string $protocol) {}

    /** @param list<array{0: string, 1: string}> $fields */
    public function request(array $fields): ValidatedRequestHead
    {
        [$pseudo, $regular] = $this->split($fields, true);
        $method = $pseudo[':method'] ?? null;
        if ($method === null || $method === '') {
            throw new HeaderValidationException(sprintf('%s request is missing :method.', $this->protocol));
        }
        $this->validateMethod($method);

        [$target, $authority, $scheme] = $this->requestTarget($method, $pseudo);
        $regular = $this->normalizeAuthority($authority, $scheme, $regular);

        return new ValidatedRequestHead($method, $target, $regular, $this->contentLength($regular));
    }

    /** @param list<array{0: string, 1: string}> $fields */
    public function trailers(array $fields): Headers
    {
        [, $regular] = $this->split($fields, false);

        foreach (['content-length', 'host'] as $forbidden) {
            if ($regular->has($forbidden)) {
                throw new HeaderValidationException(sprintf(
                    '%s trailer field "%s" is not permitted.',
                    $this->protocol,
                    $forbidden,
                ));
            }
        }

        return $regular;
    }

    /** @param array<string, string> $pseudo */
    private function addPseudo(
        array &$pseudo,
        string $name,
        string $value,
        bool $allowPseudo,
        bool $sawRegular,
    ): void {
        if (!$allowPseudo || $sawRegular) {
            throw new HeaderValidationException(sprintf(
                '%s pseudo-headers must precede regular headers and are forbidden in trailers.',
                $this->protocol,
            ));
        }
        if (!in_array($name, [':method', ':scheme', ':authority', ':path'], true)) {
            throw new HeaderValidationException(sprintf(
                'Unsupported %s pseudo-header "%s".',
                $this->protocol,
                $name,
            ));
        }
        if (isset($pseudo[$name])) {
            throw new HeaderValidationException(sprintf(
                'Duplicate %s pseudo-header "%s".',
                $this->protocol,
                $name,
            ));
        }
        if ($this->invalidValue($value)) {
            throw new HeaderValidationException(sprintf(
                'Invalid control character in %s pseudo-header value.',
                $this->protocol,
            ));
        }

        $pseudo[$name] = $value;
    }

    private function contentLength(Headers $headers): ?int
    {
        $values = $headers->all('content-length');
        if ($values === []) {
            return null;
        }

        $parsed = array_map($this->parseLength(...), $values);
        if (count(array_unique($parsed, SORT_REGULAR)) > 1) {
            throw new HeaderValidationException(sprintf(
                'Conflicting %s Content-Length fields are forbidden.',
                $this->protocol,
            ));
        }

        return $parsed[0];
    }

    private function host(Headers $regular): ?string
    {
        $values = $regular->all('host');
        if (count($values) > 1) {
            throw new HeaderValidationException(sprintf(
                '%s request must not contain multiple Host fields.',
                $this->protocol,
            ));
        }

        $host = $values[0] ?? null;
        if ($host !== null && trim($host) === '') {
            throw new HeaderValidationException(sprintf('%s Host must not be empty.', $this->protocol));
        }

        return $host;
    }

    private function invalidValue(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1;
    }

    private function normalizeAuthority(?string $authority, ?string $scheme, Headers $regular): Headers
    {
        $host = $this->host($regular);
        $this->validateAuthority($authority, $scheme, $host);
        if ($authority === null || $host !== null) {
            return $regular;
        }

        return new Headers([new HeaderField('host', $authority), ...$regular->fields()]);
    }

    private function parseLength(string $value): int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new HeaderValidationException(sprintf('Invalid %s Content-Length.', $this->protocol));
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            throw new HeaderValidationException(sprintf(
                '%s Content-Length exceeds platform integer range.',
                $this->protocol,
            ));
        }

        return (int) $normalized;
    }

    private function regularField(string $name, string $value): HeaderField
    {
        if (isset(self::FORBIDDEN[$name])) {
            throw new HeaderValidationException(sprintf(
                'Connection-specific %s field "%s" is forbidden.',
                $this->protocol,
                $name,
            ));
        }
        if ($name === 'te' && strtolower(trim($value)) !== 'trailers') {
            throw new HeaderValidationException(sprintf(
                '%s TE field may contain only "trailers".',
                $this->protocol,
            ));
        }

        try {
            return new HeaderField($name, $value);
        } catch (\InvalidArgumentException $exception) {
            throw new HeaderValidationException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param array<string, string> $pseudo
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function requestTarget(string $method, array $pseudo): array
    {
        $authority = $pseudo[':authority'] ?? null;
        if (strcasecmp($method, 'CONNECT') === 0) {
            if ($authority === null || $authority === '' || isset($pseudo[':scheme']) || isset($pseudo[':path'])) {
                throw new HeaderValidationException(sprintf(
                    '%s CONNECT requires :authority and forbids :scheme/:path.',
                    $this->protocol,
                ));
            }

            return [$authority, $authority, null];
        }

        $scheme = $pseudo[':scheme'] ?? null;
        $path = $pseudo[':path'] ?? null;
        if ($scheme === null || $scheme === '' || $path === null) {
            throw new HeaderValidationException(sprintf(
                '%s request requires :scheme and :path.',
                $this->protocol,
            ));
        }
        $this->validateScheme($scheme);

        if ($this->schemeRequiresAuthority($scheme) && $path === '') {
            throw new HeaderValidationException(sprintf(
                '%s %s :path must not be empty.',
                $this->protocol,
                strtolower($scheme),
            ));
        }
        if ($this->schemeRequiresAuthority($scheme) && $path !== '*' && !str_starts_with($path, '/')) {
            throw new HeaderValidationException(sprintf(
                '%s %s :path must be absolute or "*".',
                $this->protocol,
                strtolower($scheme),
            ));
        }
        if ($path === '*' && strcasecmp($method, 'OPTIONS') !== 0) {
            throw new HeaderValidationException(sprintf(
                '%s :path "*" is permitted only for OPTIONS.',
                $this->protocol,
            ));
        }
        if (str_contains($path, '#')) {
            throw new HeaderValidationException(sprintf(
                '%s :path must not contain a fragment.',
                $this->protocol,
            ));
        }

        return [$path, $authority, $scheme];
    }

    private function schemeRequiresAuthority(string $scheme): bool
    {
        return in_array(strtolower($scheme), ['http', 'https'], true);
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
            $this->validateName($name);
            if (str_starts_with($name, ':')) {
                $this->addPseudo($pseudo, $name, $value, $allowPseudo, $sawRegular);

                continue;
            }

            $sawRegular = true;
            $regular[] = $this->regularField($name, $value);
        }

        return [$pseudo, new Headers($regular)];
    }

    private function validateAuthority(?string $authority, ?string $scheme, ?string $host): void
    {
        if ($authority !== null && trim($authority) === '') {
            throw new HeaderValidationException(sprintf('%s :authority must not be empty.', $this->protocol));
        }

        try {
            $normalizedAuthority = $authority === null ? null : AuthorityValidator::normalize($authority, $scheme);
            $normalizedHost = $host === null ? null : AuthorityValidator::normalize($host, $scheme);
        } catch (InvalidArgumentException $error) {
            throw new HeaderValidationException(
                sprintf('%s request contains an invalid authority: %s', $this->protocol, $error->getMessage()),
                previous: $error,
            );
        }

        $this->validateHttpAuthority($authority, $scheme, $host);

        if ($normalizedAuthority !== null && $normalizedHost !== null && !hash_equals($normalizedAuthority, $normalizedHost)) {
            throw new HeaderValidationException(sprintf(
                '%s :authority conflicts with Host.',
                $this->protocol,
            ));
        }
    }

    private function validateHttpAuthority(?string $authority, ?string $scheme, ?string $host): void
    {
        if ($scheme === null || !$this->schemeRequiresAuthority($scheme)) {
            return;
        }
        if ($authority === null && $host === null) {
            throw new HeaderValidationException(sprintf(
                '%s %s request requires :authority or Host.',
                $this->protocol,
                strtolower($scheme),
            ));
        }

        // Syntax and userinfo are validated for every authority in validateAuthority().
    }

    private function validateMethod(string $method): void
    {
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $method) !== 1) {
            throw new HeaderValidationException(sprintf('%s :method is invalid.', $this->protocol));
        }
    }

    private function validateName(string $name): void
    {
        if ($name === '' || strtolower($name) !== $name) {
            throw new HeaderValidationException(sprintf(
                '%s header field names must be lowercase and non-empty.',
                $this->protocol,
            ));
        }
    }

    private function validateScheme(string $scheme): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/D', $scheme) !== 1) {
            throw new HeaderValidationException(sprintf('%s :scheme is invalid.', $this->protocol));
        }
    }
}
