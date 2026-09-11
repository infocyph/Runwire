# Runwire

A high-performance process and network runtime for PHP.

Runwire 1.0 is under active development for the Foundation 3 launch. It provides the low-level runtime boundary for process supervision, event-loop and network server mechanics while remaining framework agnostic.

## Baseline

- PHP `^8.4`
- `ext-pcntl`
- `ext-posix`
- PHPForge `dev-main@dev` for development QA

Optional runtime capabilities such as native TLS/ALPN and accelerated event-loop backends are detected and validated separately.

## Architecture

Runwire owns generic runtime mechanics only. Foundation owns application lifecycle and execution scopes, Webrick owns application HTTP semantics, and Omnibus owns messaging/queue semantics.

The 1.0 native HTTP target is HTTP/1.1 plus HTTP/2. HTTP/3/QUIC is intentionally post-1.0 work.

See `docs/plans/runwire-1.0-foundation-3-launch-plan.md` for the canonical development plan.
