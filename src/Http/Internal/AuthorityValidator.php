<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use InvalidArgumentException;

/**
 * Validates and canonicalizes HTTP authority values without accepting URI userinfo.
 */
final readonly class AuthorityValidator
{
    /**
     * Return a comparison-safe authority after validating its host and optional port.
     */
    public static function normalize(string $authority, ?string $scheme = null): string
    {
        if ($authority === '' || strpbrk($authority, "\x00\x09\x0A\x0D /?#@") !== false) {
            throw new InvalidArgumentException('HTTP authority contains forbidden characters.');
        }

        [$host, $port] = str_starts_with($authority, '[')
            ? self::ipLiteral($authority)
            : self::registeredName($authority);

        $scheme = strtolower($scheme ?? '');
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return $port === null ? $host : $host . ':' . $port;
    }

    /** @return array{0: string, 1: ?int} */
    private static function ipLiteral(string $authority): array
    {
        $close = strpos($authority, ']');
        if ($close === false || $close === 1) {
            throw new InvalidArgumentException('HTTP authority contains an invalid IP literal.');
        }

        $literal = substr($authority, 1, $close - 1);
        $suffix = substr($authority, $close + 1);
        $normalized = self::normalizeIpLiteral($literal);

        return ['[' . $normalized . ']', self::port($suffix)];
    }

    private static function normalizeIpLiteral(string $literal): string
    {
        if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($literal);
            $normalized = $packed === false ? false : inet_ntop($packed);
            if (is_string($normalized)) {
                return strtolower($normalized);
            }
        }

        if (preg_match("/^v[0-9A-F]+\.[A-Za-z0-9._~!$&'()*+,;=:-]+$/Di", $literal) === 1) {
            return strtolower($literal);
        }

        throw new InvalidArgumentException('HTTP authority contains an invalid IP literal.');
    }

    private static function port(string $suffix): ?int
    {
        if ($suffix === '') {
            return null;
        }
        if ($suffix[0] !== ':' || strlen($suffix) === 1 || preg_match('/^[0-9]+$/D', substr($suffix, 1)) !== 1) {
            throw new InvalidArgumentException('HTTP authority contains an invalid port.');
        }

        $port = (int) substr($suffix, 1);
        if ($port > 65_535) {
            throw new InvalidArgumentException('HTTP authority port exceeds 65535.');
        }

        return $port;
    }

    /** @return array{0: string, 1: ?int} */
    private static function registeredName(string $authority): array
    {
        if (substr_count($authority, ':') > 1) {
            throw new InvalidArgumentException('IPv6 HTTP authorities must use brackets.');
        }

        $colon = strrpos($authority, ':');
        $host = $colon === false ? $authority : substr($authority, 0, $colon);
        $suffix = $colon === false ? '' : substr($authority, $colon);
        if ($host === '' || preg_match("/^(?:[A-Za-z0-9._~-]|%[0-9A-F]{2}|[!$&'()*+,;=])+$/Di", $host) !== 1) {
            throw new InvalidArgumentException('HTTP authority contains an invalid registered name.');
        }

        return [strtolower($host), self::port($suffix)];
    }
}
