# Runwire 3.0 final candidate review — 2026-10-29

Reviewed candidate: `b1b77a15c150e02fceac2599fff7b9448eaa7f0e` (PR #9).

**Disposition: R01–R06 and archive packaging corrected in the working tree;
qualify the correction commit before release.** The maintainer requested resolution of all findings,
reopening the affected source owners. Canonical task acceptance and previous
certification evidence remain historical; they do not certify these changes.

The review follows `vendor/infocyph/phpforge/resources/engineering-principles.md`.
It covers the 3.0 plans and handoff, current GitHub evidence, and critical
outbound HTTP, pooling, DNS, address-policy, TLS/proxy, executor/filesystem,
coroutine, tracing and supervisor paths. It is not a claim that every source
line or native platform was independently exercised locally.

## Corrections implemented

| Finding | Result | Regression coverage |
| --- | --- | --- |
| R01 | Require globally reachable addresses after IPv4-mapped normalization; explicitly reject IPv4/IPv6 multicast. | Special-use ranges, mapped addresses, direct/resolved dial rejection and CONNECT/SOCKS5 rejection before bytes are sent. |
| R02 | Validate H2 response lengths before final headers, enforce permitted DATA length, and check END_STREAM before success. Pass HEAD context through both session types. | Invalid/duplicate/overflow lengths, incomplete/excess content, bodyless statuses, HEAD metadata and multiplexed sibling isolation. |
| R03 | Pass HEAD context to root and sibling H3 response owners; use shared strict length/bodyless semantics while retaining ordinary FIN validation. | HEAD/304 metadata beyond body capacity, forbidden DATA, existing ordinary length failures, and independent QPACK-delayed HEAD/GET completion after FIN. |
| R04 | Observe the earliest effective deadline while queued with the existing loop-aware CancellationWait; retire its timer/subscription on admission or failure. Settle an owner before releasing its lease. | Request/inherited deadlines, already-expired admission, manual cancellation, grant/expiry, session close, and resource cleanup. |
| R05 | Settle every coalesced DNS consumer before rethrowing the first consumer exception; apply the same cleanup to close. Track lookup generations to protect reentrant groups. | Throwing first/middle consumers on success/error, throwing close, cancellation/reentrancy and synchronous upstream completion. |
| R06 | Use the unbracketed IPv6 address as TLS identity while preserving bracketed URI/Host authority. | Explicit/default trust policy, real IPv6 IP-SAN TLS, two-request keepalive reuse, non-default port and wrong-origin/peer rejection. |
| R07 | Centralize archive exclusions in .gitattributes and remove the Composer archive block as requested. Exclude generated analysis, local data/caches and development-only payloads. | Actual Composer archive with local-data sentinels, Git export check, byte comparison of all 448 runtime files and an isolated no-dev consumer. |

No production class/file, dependency, feature family or quality suppression was
added. The pending-admission helper is created only for queued requests. Fixed
protocol policy defaults, public method call shapes and measurement budgets
are preserved. Migration notes describe the corrected outbound behavior.

## Historical reproductions at the reviewed candidate

### R01 — P1: the public-IP policy admits non-global destinations

Owner: `src/Network/OutboundAddressPolicy.php:35`.

`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` does not mean
globally reachable public IP. On the reviewed runtime, `assertCandidate()`
accepts `100.64.0.1`, `198.18.0.1`, `192.0.2.1`, `2001:db8::1` and
`::ffff:100.64.0.1`. The current tests cover RFC1918, loopback and link-local
cases but miss these ranges. Shared address space can reach internal
services on networks that route it, defeating the advertised SSRF boundary.

Use a positive global-address check after IPv4-mapped normalization and
explicitly decide multicast/other special-purpose treatment. Preserve
numeric validation and the connected-peer equality check. PHP's
[`FILTER_FLAG_GLOBAL_RANGE`](https://www.php.net/manual/en/filter.constants.validation.php#constant.filter-flag-global-range)
is available below the supported PHP minimum; its distinction from the two
existing flags is documented by PHP. Do not assume it alone excludes every
special destination: the local probe also accepted multicast addresses.

Required tests: direct and resolved dials, proxy target policy, mapped IPv4,
shared/benchmark/documentation ranges, IPv4/IPv6 multicast, and positive
public IPv4/IPv6 controls. Denials must happen before socket admission.

### R02 — P1: H2 accepts truncated, oversized and malformed response bodies

Owner: `src/Http/Client/Internal/Http2ClientResponseParser.php:99` and `:138`.

The parser never validates or retains `content-length`. These complete frame
sequences all succeed and set `done()` to true:

| Status | Content-Length | DATA bytes | Required outcome |
| --- | --- | ---: | --- |
| 200 | 5 | 3 | Reject incomplete content |
| 200 | 1 | 3 | Reject excess content |
| 200 | not-a-number | 3 | Reject malformed length |
| 204 | 0 | 3 | Reject forbidden content |

Both one-shot and multiplexed H2 sessions use this parser; the multiplexer
completes the stream with a null error when `done()` becomes true. This can
report corrupted downloads as successful and exposes malformed content to
forwarding applications. This violates
[RFC 9113 section 8.1.1](https://www.rfc-editor.org/rfc/rfc9113.html#section-8.1.1).

Reuse the existing strict length parser, track expected/received content,
and validate END_STREAM in HEADERS and DATA. Preserve HEAD/304 metadata
length exceptions while rejecting body bytes for no-content responses.
Reject invalid/ambiguous length fields before publishing final headers.

Required tests: short/excess/invalid/duplicate/overflow length, header-only
END_STREAM with a nonzero expected body, 204/304 DATA rejection, valid HEAD
metadata, valid ordinary streaming, and independent multiplexed siblings.

### R03 — P1: H3 loses request-method/bodyless response semantics

Owners: `src/Http/Client/Http3OutboundClient.php:504`,
`src/Http/Client/Internal/Http3ClientSiblingManager.php:362`, and
`src/Http/Client/Internal/Http3ClientResponseStream.php:83`.

Neither root nor sibling construction passes the request method to the
response owner. A valid bodyless HEAD response with status 200 and
`content-length: 123` fails with `HTTP/3 response Content-Length mismatch`.
Conversely, status 204 without Content-Length followed by DATA `abc` reaches
`isFinished() === true` and publishes `abc`. The response owner enforces
ordinary length accounting without the HTTP no-content exceptions.

Carry request-method context into each response owner, validate response
status semantics, and keep body-size/length enforcement for ordinary
responses. Cover root and sibling paths, including QPACK-delayed headers.
Do not weaken the existing length mismatch check to accept HEAD.

Required tests: HEAD with nonzero metadata length and FIN, HEAD with DATA,
204/304 with DATA, valid empty responses, normal length mismatch, and
QPACK-blocked root/sibling response completion. Native QUIC interoperability
must confirm the fix in the prepared environment.

### R04 — P1: pooled H1 requests miss deadlines while waiting for a lease

Owner: `src/Http/Client/PooledHttp1Session.php:160`.

`send()` creates a deadline-bearing cancellation source but acquires a lease
using the pool's independent default 10-second wait. `onCancel()` only
refreshes deadline state when called; it does not schedule expiration.
The connected-exchange deadline is armed only after a lease is granted.

A real loopback reproduction holds the only active connection, then queues
a request with a 20 ms deadline. At 100 ms the second callback has not run,
its handle is unsettled, and the waiter count is still one. Expired requests
therefore retain bounded admission capacity and delay caller settlement.

Arm the effective earliest request/token deadline during admission, cancel
the queued waiter when it expires, and clean up its timer/subscription on
every grant, cancellation, failure and close path. Preserve exactly-once
completion and the deadline cancellation reason.

Required tests: request deadline, inherited token deadline, manual cancel,
already-expired request, simultaneous grant/expiry, session close, and zero
remaining waiters/timers/subscriptions after completion.

### R05 — P2: a throwing DNS consumer strands coalesced callers

Owners: `src/Network/CachedDnsResolver.php:166` and `:280`.

`completeHost()` removes the pending group before invoking its consumers.
The first throwing consumer exits the fan-out loop. The second consumer is
never invoked and remains registered with no pending upstream job. A probe
with two coalesced callers reports `pendingHosts=0`, `waiters=1`, and no
second completion after the first callback throws. An unlimited caller can
remain stranded until explicit cancellation/close.

Retire and settle all original group members despite individual callback
exceptions. Then surface the first exception according to the library's
existing callback policy. Apply the same cleanup discipline to `close()`.

Required tests: first/middle consumer throws, error-result fan-out, throwing
close callback, cancellation/reentrant lookup during fan-out, and zero
orphaned waiters/subscriptions/timers.

### R06 — P2: pooled HTTPS cannot use an IPv6 literal origin

Owner: `src/Http/Client/PooledHttp1Session.php:92`.

The session retains `parse_url()`'s bracketed host as the TLS peer name.
The default `https://[::1]` session consequently creates `[::1]` as the
verification identity, while `Http1OutboundClient` requires the unbracketed
`::1`. Sending a request fails before dialing. Supplying the correct
`TlsClientOptions('::1')` instead fails the session constructor's origin
comparison. Both paths were reproduced without relying on IPv6 routing.

Separate the URI authority representation from the numeric TLS identity.
Normalize the verification name consistently with the existing standalone
client, while retaining brackets in the wire Host authority and preserving
origin/port isolation.

Required tests: explicit/default IPv6 TLS policies, real IP-SAN verified TLS
on IPv6, correct wire Host, non-default port, and wrong-peer rejection.

### R07 — additional packaging finding during correction validation

An actual local `composer archive` included ignored Graphify output and local
tool directories, producing 64,279,724 uncompressed bytes. Git ignore entries
alone had not excluded that data. Composer's GitExcludeFilter reads
`.gitattributes` export-ignore entries; separate Composer archive rules are
unnecessary once those entries cover the full development/local surface.

At the maintainer's request, remove `archive.exclude` from `composer.json` and
use `.gitattributes` as the archive policy, including the previously missing
`composer.lock` entry. The regression archives an isolated fixture containing
local-data sentinels without Composer archive exclusions. Final real Composer
and Git archives exclude the unwanted roots. The working-tree Composer archive
contains all 448 tracked runtime files byte-for-byte, is 1,932,786 bytes
uncompressed, and passes an isolated no-dev install, platform checks and runtime
autoload/parser/address-policy smoke checks. The Git archive check applies the
working-tree attributes to the base commit and does not claim to contain the
uncommitted runtime fixes.

## Current release evidence

The older W236/H10 wording is stale; do not ask for already-passing runs
solely because that snapshot still says OPEN.

- [Release Certification #40743235500](https://github.com/infocyph/Runwire/actions/runs/40743235500)
  passed. The final job log identifies the reviewed SHA, `open_tasks=0`,
  `certification_ready=true`, `release_tag_created=false`, and
  `package_published=false`.
- [Independent H2 #40804935495](https://github.com/infocyph/Runwire/actions/runs/40804935495)
  passed on that SHA. Its raw job log confirms five trials per population,
  baseline/candidate CV 0.3445%/0.5340%, real client headroom and the unchanged
  comparison checks. The PR's statement that this run is in progress is stale.
- [WebSocket #40740131352](https://github.com/infocyph/Runwire/actions/runs/40740131352)
  and [mixed soak #40740151356](https://github.com/infocyph/Runwire/actions/runs/40740151356)
  report success in the current-SHA workflow listing.
- PR #9 remains draft and unmerged. These successful checks are evidence
  for the reviewed candidate; they do not exercise the new adversarial
  cases above or certify a future correction commit.

## Local validation and tooling update

The stale ignored development lock/autoload installation has been refreshed
through Composer. All five PSR development requirements are installed.
The local PHPUnit 13.4.1 affected by
[GHSA-9vw2-f4vp-x7gf](https://github.com/sebastianbergmann/phpunit/security/advisories/GHSA-9vw2-f4vp-x7gf)
was replaced with the compatible, unaffected PHPUnit 13.3.6/Pest 5.3.0
combination. Current Composer audit reports **zero advisories**; the existing
development-only doctrine/annotations abandonment warning is non-blocking.
No tracked dependency constraint or runtime dependency was added or changed,
and vendor files were not patched manually.

Validation of the corrected working tree:

- `composer ic:doctor`, `ic:list-config` and `ic:active-config` completed.
- `composer ic:process` passed normalize, Rector, Pint and PHPCBF with no
  violations. The active complexity limits remain 12 per method/80 per class.
- The eight affected test files passed **111 tests / 1,043 assertions** in the
  focused run, including real IPv6 TLS. A subsequent full host run includes
  an additional callback assertion.
- `composer ic:tests:details` on PHP 8.5.4 passed PHPStan, Psalm, Deptrac,
  syntax, clone/comment policy, formatting and Rector. Pest reported
  **1,185 passed / one risky / 50,424 assertions**. This host lacks ext-event:
  the reference check and one ext-event-only test prevent a clean aggregate
  result. They are covered by the configured native run below.
- Property-aware source migration from immutable 2.1.2 source passed the
  unchanged migration policy: two trait relocations, six reviewed signature
  changes, 98 added types, and **35/35** changed public implementations with
  test-backed dispositions. These checks cover the working tree, using the
  base SHA as a local manifest label; they are not exact-commit certification.
- The prepared PHP 8.5.11 container has ext-event and Swoole. Its QA settings
  use `nofile=65536`, `display_errors=stderr` and `memory_limit=2G`. The initial
  image defaults caused descriptor-capacity/error-channel test failures and
  a 128-MiB analyzer OOM; these were environment failures, and no gate was
  weakened. Final **`composer ic:release:guard` passed (exit 0)**: strict
  Composer validation, stable runtime constraints, advisory audit, syntax,
  references, clone/comment policy, Pest, Pint, PHPCS, Deptrac, PHPStan,
  Psalm and Rector. Pest passed **1,187 tests / 50,471 assertions** with
  no risky/skipped tests. This is local container evidence for the corrected
  working tree, not hosted certification of a committed candidate.
- The independent native H3 harness now adds a HEAD root and HEAD sibling
  beside two GET siblings, preserving its original four-GET acceptance run.
  The peer confirms nonzero HEAD metadata and sends FIN without DATA. Both
  PHP/shell and Python syntax checks pass. Execute the real check in the
  ext-quic environment with `RUNWIRE_AIOQUIC_PYTHON=/path/to/venv/bin/python
  bash tests/interop/http3_outbound_client.sh /tmp/outbound-h3.json`; native
  execution of this added scenario is still pending there.

## Remaining release qualification

1. Run supported PHP 8.4/8.5 stable/lowest and affected native protocol,
   host and independent-consumer CI on the correction commit. In particular,
   the local environments lack ext-quic: R03 has decoded-stream/QPACK tests
   and caller-path inspection here, but needs native QUIC interoperability.
2. Refresh matched sustained-performance/soak and fail-closed certification
   evidence for that final SHA using existing budgets. These correctness
   changes do not establish an RPM improvement or performance parity.
3. Update the handoff/PR evidence to that SHA before release. Retain the
   historical successful/failed runs without treating them as proof for
   changed source. No merge, tag or publication is performed by this repair.

Automatic cross-protocol session management, PSR-18/Revolt adapters and SDK
export remain the explicitly deferred roadmap work. They are not additional
release blockers introduced by this review.
