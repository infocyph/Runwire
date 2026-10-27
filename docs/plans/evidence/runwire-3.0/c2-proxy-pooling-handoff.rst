C2 — Proxy, pooling and relay: scoped handoff (Runwire 3.0)
=============================================================

Status: C2 QA-accepted 5/5 in explicitly supported Runwire 3.0 scope.
This file is internal engineering handoff evidence, not a release certificate.

Scope
-----

Runwire 3.0 retains ordinary HTTP/1.1, HTTP/2, HTTP/3, QUIC streams,
verified TCP/TLS, SOCKS5, HTTP CONNECT, connection pooling and relays.
WebTransport client/server/session/datagrams and its design task IDs
are completely excluded. The selected PHP native QUIC backend does not
provide the needed RFC 9221 QUIC DATAGRAM interface; ordinary native QUIC
capability refusals remain in place for non-WebTransport callers.

C2 mapping: U10.03, U10.04, U10.05, U10.06, U10.09. The first four
previously accepted task IDs (U10.03, U10.04, U10.05, U10.09)
retain immutable independent reference evidence from their plan rows.

U10.06 — supported protocol boundary
------------------------------------

Supported: cleartext CONNECT negotiation followed by a new, independently
verified TLS connection to the original destination, with SNI,
certificate trust/name, ALPN and application-data interoperation.
Negative identity-mismatch test sends no application data. An already
TLS-encrypted HTTPS proxy cannot be treated as an origin-verification
channel. Attempted TLS-on-TLS is explicitly rejected and aborts that
proxy connection, including when a native crypto context is discovered.

This is NOT a successful HTTPS-proxy to HTTPS-origin nested TLS feature;
PHP stream crypto is not relied upon to synthesize another layer.
Never report the unsupported path as passing positive interoperability.
See docs/features.rst and docs/connection-policies-3.0.rst.

Independent tests and QA
------------------------

- tests/VerifiedOriginalTargetTlsInteropTest.php and
  tests/fixtures/independent_original_target_tls_proxy.py:
  real origin certificate/SNI/ALPN and wrong-host rejection over CONNECT.
- tests/ProxyAuthenticatedTlsInteropTest.php:
  independently verified TLS proxy, credentials and nested-upgrade
  refusal with no origin-verified socket exposure.
- tests/VerifiedTargetTlsUpgradeGuardTest.php:
  callback ownership, fail-closed encrypted-proxy refusal, no leaked
  watchers or callbacks.
- tests/HttpConnectIndependentRelayTest.php:
  independently authenticated proxy, real origin byte relay and 407.
- tests/QuicClientOptionsTest.php and
  tests/ReleaseScopeExclusionTest.php:
  ordinary QUIC safety and zero WebTransport implementation in src/.
- tests/Plan3TrackerSynchronizationTest.php:
  canonical accepted count and unique C1–C5 mapping.

Acceptance must be based on all nine PR workflows passing on one immutable
source. For U10.03, U10.04, U10.05 and U10.09 immutable links remain on
their checklist rows. The implementation checkpoint before the C2
plan freeze was commit 7d71b589acfb9e2fa6b88c6513e08dd22c1fad2c.
All nine routine PR workflows GREEN at that immutable implementation SHA:
- Security: https://github.com/infocyph/Runwire/actions/runs/40455457270
- Benchmarks: https://github.com/infocyph/Runwire/actions/runs/40455456942
- Source Audit: https://github.com/infocyph/Runwire/actions/runs/40455456949
- Sphinx: https://github.com/infocyph/Runwire/actions/runs/40455456992
- Independent HTTP2: https://github.com/infocyph/Runwire/actions/runs/40455456939
- Release Candidate: https://github.com/infocyph/Runwire/actions/runs/40455456938
- Portable Native: https://github.com/infocyph/Runwire/actions/runs/40455456940
- Hosted: https://github.com/infocyph/Runwire/actions/runs/40455456972
- Swoole: https://github.com/infocyph/Runwire/actions/runs/40455456937

Adaptive H1/H2 Benchmarks passed too; the implementation source has
complete nine-green QA. Subsequent tracker and handoff document commits
require independent exact-source QA before claiming the final branch
tip itself is green.

Handoff
-------

Both canonical trackers now record U10 9/9, C2 5/5, 139/169 accepted
with 30 open. Proceed to C3 once the handoff head passes routine QA.
Do not silently alter 2.1.2-compatible public behavior, remove ordinary
HTTP/3 or native QUIC protections, count WebTransport tasks, or
merge/tag/release. The major/minor decision remains pending the final
backward-compatibility and behavioral certification.
