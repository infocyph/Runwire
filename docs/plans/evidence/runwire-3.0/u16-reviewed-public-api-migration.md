# Runwire 3.0 — verified published 2.1.2 public-API migration audit (W98)

**Source comparator:** published Runwire **2.1.2** at immutable
`7e9bf20ad0737a187dfed77b723791b141b36378`.
**Development snapshot:** W97 PR-head Source Audit
[#40163809510](https://github.com/infocyph/Runwire/actions/runs/40163809510).
**Scope:** Runwire only. **Status:** reviewed *source declarations* and
reflection regression; **NOT** complete runtime behavior, migration/U16.12,
maintainer release approval, or API freeze.

The Source Audit exports both tags using exactly the current
`tools/public-api-manifest.php --with-properties` exporter, preserving the
original immutable 2.1.2 baseline lock. It archives all JSON manifests, a
deterministic diff, and a machine-reviewed allowlist. At W97:

| Source-only observation | Result | Disposition |
| --- | ---: | --- |
| Published 2.1.2 PHP public types | 350 | Unmodified frozen source |
| Current W97 public types | 414 | Unreleased 3.0 development |
| Added named types | 64 | Additive, but availability depends on backend/host |
| Apparent removed methods | 2 | **Neither actually removed:** both are now trait-composed by `Connection` |
| Changed declared signatures | 6 | Constructor **appends optional arguments**; default behavior preserved in source |
| Source implementation hashes changed (declarations unchanged) | 26 | Behavioral review, fuzz and host certification remain separate |
| Property inventory coverage | Complete | Both snapshots used the same `--with-properties` exporter |

## Two false-positive removed declarations

- `Network\Connection::onClose(callable $callback): self` moved to
  `Network\Internal\ConnectionCloseObserverTrait`. `Connection` still
  uses this trait. Public runtime reflection preserves the method.
- `Network\Connection::write(string $data): WriteResult` moved to
  `Network\Internal\ConnectionLifecycleOperationsTrait`. `Connection`
  still uses this trait. Public runtime reflection preserves the method.

The source-only manifest intentionally does not resolve trait composition.
**Do not treat these records as API removals.** The executable
`tests/PublicApiMigrationCompatibilityTest.php` checks their public,
typed, required-parameter signatures, the composing trait names, and
reflective method availability.

## Six reviewed constructor signature extensions

| Existing public constructor | Addition | Compatibility judgment |
| --- | --- | --- |
| `Http\Http2\Internal\ResponseScheduler` | Optional outbound DATA credit closure | Existing arguments and defaults remain valid; stream-credit behavior still needs peer tests |
| `Http\Http3\Quic\PhpQuicHttp3Worker` | Optional connection failure observer | Existing arguments still valid; H3 failure propagation remains independent QA |
| `Network\Connection` | Optional verified TLS peer fingerprint and strict EOF flag | Existing construction remains valid; strict TLS EOF opt-in |
| `Network\TlsOptions` | Optional client-certificate requirement flag | Existing server TLS calls retain default |
| `WebSocket\Internal\WebSocketFrameParser` | Optional client masking expectation flag | Existing server parser default unchanged |
| `WebSocket\WebSocketSession` | Optional client-mode flag | Existing server session default unchanged |

`u16-reviewed-api-delta.json` pins the SHA-256 **before and after**
signature text for each of the six accepted *source-level* deviations.
`tools/public-api-migration-audit.php` fails on any new apparent removals,
property/enum/constant changes, modified reviewed signature, incomplete
property inventory, malformed diff totals or changed published comparator.
New additive type declarations are permitted; their runtime availability
is not silently certified. This guard runs in the **existing Source Audit**
workflow, not a duplicate PR/push workflow.

## Remaining migration and release review

A passing source guard does **not** guarantee compatible thrown exception
types, lifecycle callback order, event loop ownership, worker/fork behavior,
extension loading, authentication or TLS semantics, or runtime performance.
The 26 changed implementation hashes require behavior-specific QA and a
maintainer disposition at the frozen release revision. Independent
H1/H2/QUIC/host/test matrices, H3/WT functionality, remaining migration
examples, U15 benchmarks and U17 approval remain separate gates.

**No backward-compatibility shim is required merely to preserve historic
behavior if 3.0 explicitly chooses an intentional break**; every actual
intentional break must instead be listed with before/after code, security
and operational migration instructions before U16.12 is accepted.
