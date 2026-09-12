# Runwire

A high-performance process and network runtime for PHP.

Runwire 1.0 is under active development for the Foundation 3 launch. It provides the low-level runtime boundary for process supervision, event-loop and network server mechanics while remaining framework agnostic.

## Baseline

- PHP `^8.4`
- `ext-pcntl`
- `ext-posix`
- PHPForge `dev-main@dev` for development QA

Optional runtime capabilities such as native TLS/ALPN, accelerated event-loop backends, and QUIC are detected and validated separately. Ordinary Runwire installation remains valid without QUIC; selecting native HTTP/3 fails fast when the required QUIC capability is unavailable.

## Architecture

Runwire owns generic runtime mechanics only. Foundation owns application lifecycle and execution scopes, Webrick owns application HTTP semantics, and Omnibus owns messaging/queue semantics.

The Runwire 1.0 native HTTP target includes HTTP/1.1, HTTP/2, and HTTP/3 over QUIC. HTTP/3 support is capability-based rather than tied to any Linux distribution or distro-specific client package.

See `docs/plans/runwire-1.0-foundation-3-launch-plan.md` for the canonical development plan.
