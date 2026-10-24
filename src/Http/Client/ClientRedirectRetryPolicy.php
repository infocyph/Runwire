<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Http\Headers;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Explicit, bounded HTTP redirect/credential/cookie/retry policy.
 *
 * No ambient cookie jar or implicit credential forwarding is supported.
 * Unsafe replay, protocol downgrade and ambiguous authorities fail closed.
 */
final readonly class ClientRedirectRetryPolicy
{
    public function __construct(
        public int $maxRedirects = 4,
        public int $maxRetries = 1,
        public bool $allowCrossOrigin = false,
    ) {
        if ($maxRedirects < 0 || $maxRedirects > 10 || $maxRetries < 0 || $maxRetries > 3) {
            throw new InvalidArgumentException('HTTP redirect and retry limits must be finite and bounded.');
        }
    }

    /** Whether a status can initiate one explicitly bounded redirect. */
    public function isRedirect(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }

    /**
     * Preserve only safely replayable requests, with explicit credential
     * stripping for every changed origin including the port and scheme.
     */
    public function redirect(ClientRequest $request, string $location, int $redirectsUsed): ClientRequest
    {
        if ($redirectsUsed >= $this->maxRedirects || $redirectsUsed < 0) {
            throw new RuntimeException('HTTP redirect budget exhausted.');
        }
        if (!in_array(strtoupper($request->method), ['GET', 'HEAD'], true) || $request->body !== null) {
            throw new RuntimeException('Unsafe HTTP redirect would replay a non-idempotent or streaming request.');
        }
        $uri = self::resolve($request->uri, $location);
        $original = self::origin($request->uri);
        $next = self::origin($uri);
        if ($original[0] === 'https' && $next[0] !== 'https') {
            throw new RuntimeException('HTTPS-to-plaintext HTTP redirect is forbidden.');
        }
        $sameOrigin = $original === $next;
        if (!$sameOrigin && !$this->allowCrossOrigin) {
            throw new RuntimeException('Cross-origin redirects require explicit opt-in.');
        }

        $allowedCrossOrigin = ['accept', 'accept-encoding', 'accept-language', 'user-agent'];
        $drop = ['host', 'content-length', 'transfer-encoding', 'connection', 'proxy-authorization'];
        $fields = [];
        foreach ($request->headers->fields() as $field) {
            if (in_array($field->name, $drop, true)
                || (!$sameOrigin && !in_array($field->name, $allowedCrossOrigin, true))) {
                continue;
            }
            $fields[] = $field;
        }

        return new ClientRequest(
            method: $request->method,
            uri: $uri,
            headers: new Headers($fields),
            protocol: $request->protocol,
            cancellation: $request->cancellation,
            deadline: $request->deadline,
        );
    }

    /** Retry only a bodyless safe method before any response was published. */
    public function shouldRetry(
        ClientRequest $request,
        int $retriesUsed,
        ?Throwable $error,
        bool $responseStarted,
    ): bool {
        return !$responseStarted
            && $error instanceof RuntimeException
            && $retriesUsed >= 0
            && $retriesUsed < $this->maxRetries
            && in_array(strtoupper($request->method), ['GET', 'HEAD'], true)
            && $request->body === null
            && !($request->cancellation?->isCancelled() ?? false)
            && !($request->deadline?->expired() ?? false);
    }

    /** @return array{string,string,int} */
    public static function origin(string $uri): array
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || !is_string($parts['scheme'] ?? null)
            || !is_string($parts['host'] ?? null)) {
            throw new InvalidArgumentException('HTTP redirect origin is invalid.');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidArgumentException('HTTP redirect scheme is unsupported.');
        }

        return [$scheme, strtolower($parts['host']), $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
    }

    private static function resolve(string $origin, string $location): string
    {
        if ($location === '' || strlen($location) > 2_048
            || preg_match('/[\x00-\x20\x7F#\\\\]/', $location) === 1
            || str_starts_with($location, '//')) {
            throw new RuntimeException('HTTP redirect location is malformed or ambiguous.');
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('HTTP redirect base origin is malformed.');
        }

        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }
        if (str_contains($location, '://') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $location) === 1) {
            throw new RuntimeException('HTTP redirect uses an unsupported protocol.');
        }
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $base = $parts['scheme'] . '://' . $authority;
        if (str_starts_with($location, '/')) {
            return $base . $location;
        }
        $path = $parts['path'] ?? '/';
        $prefix = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $base . $prefix . $location;
    }
}
