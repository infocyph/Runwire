Security model and protocol trust boundaries
============================================

Threat model at a glance
------------------------

Runwire can process input from untrusted peers, forward application
requests to remote services, reuse live sockets across operations and
spawn native worker processes. Review these boundaries independently:

* inbound bytes, headers, frames, datagrams and transport handshake;
* target hostnames, DNS answers, connected IP addresses and ports;
* certificate chain, peer hostname, SNI and ALPN;
* proxy credentials versus origin credentials;
* pool key/tenant isolation and cancellation ownership;
* file paths, worker environment, privilege and process limits;
* instrumentation that can leak request secrets.

All controls must fail closed when a required capability is missing.
A missing ext-quic implementation cannot be replaced by a fake
HTTP/3 or WebTransport compatibility mode.

Inbound defense in depth
------------------------

Set maximum request/header/body/frame sizes, connection and stream
concurrency, and timeouts appropriate to the workload. Use explicit
admission policies under hostile slow clients rather than letting
unbounded request objects accumulate. Reject malformed H1 framing,
H2 HPACK/CONTINUATION abuse, H3 QPACK/header state inconsistencies,
invalid WebSocket masking and oversized UDP packets.

A syntactically valid request may still be malicious; application
authentication, authorization, rate limiting and business validation
remain application responsibilities.

Outbound SSRF prevention
------------------------

Untrusted URL strings are an SSRF attack surface. Validate schemes,
ports, hostnames and redirects before dialing. Decide which IP ranges
are permitted, and enforce this against the **actual connected IP**,
not only an initial DNS name. Resolve DNS off-loop and do not accept
an automatic downgrade if a target becomes forbidden or a required
TLS peer check fails.

Origin host identity must be retained through Happy Eyeballs address
selection. Neither a DNS answer nor a pool key hash proves tenant or
origin authorization.

TLS peer authentication
-----------------------

A verified HTTPS session requires the intended origin hostname,
trusted certificate chain, protocol policy and ALPN. Test failure
modes for wrong SAN, untrusted CA, missing OpenSSL, invalid ALPN,
expired or bad certificate, and cancellation mid-handshake.
Do not allow secure-client options to inherit unrelated global PHP
default SSL stream contexts.

Client certificates/private keys are confidential process resources.
Do not include raw key material, proxy authentication headers, bearer
tokens or signed cookies in exception traces or metrics labels.

Proxy trust boundaries
----------------------

.. code-block:: text

   Application authority (example.com)
          |
   HTTP CONNECT over cleartext proxy
          | -- proxy credentials belong to proxy only
          v
   Tunnel byte stream
          |
   TLS handshake to original hostname (verify chain, SNI, ALPN)
          v
   Verified HTTPS application session

For an HTTPS proxy, the outer TLS layer already occupies the PHP
stream transport. Runwire does not claim supported inner TLS-over-TLS
to a second HTTPS origin. It explicitly fails closed. HTTP CONNECT
success alone does not authenticate an HTTPS origin.

Pooling and credentials
-----------------------

`PoolIsolationKey` must distinguish origin, authority, tenant,
ALPN/TLS identity, trust roots and credentials policy revision, proxy
route and authentication revision. A session may be reused only after
it is fully quiescent, no unread bytes or goaway/close state remains,
and its lifetime owner has handed it off correctly.

Cross-tenant reuse, partially framed responses and suppressed stream
errors may cause authentication or data leakage. A connection reused
outside its validated trust context is an incident, not an
optimization.

Process, file and host boundaries
---------------------------------

Use a dedicated service user with the least privileges needed for
ports, certs and writable directories. Privilege dropping must
be deliberate and must occur in the intended worker context.
Native process supervision needs POSIX/PCNTL; request-driven hosts
must not run arbitrary uncontrolled worker masters.

Off-loop filesystem operations remain subject to OS permissions,
traversal, symlinks and TOCTOU races. Moving an operation to a
subprocess does not sanitize its input or create a sandbox.

Adversarial verification
------------------------

.. list-table:: Security assurance evidence
   :header-rows: 1
   :widths: 32 68

   * - Domain
     - Verify
   * - TLS/ALPN
     - Wrong host, CA, negotiated protocol and absent extension fail closed
   * - DNS and connected IP
     - Allowed destinations enforced at lookup and socket verification
   * - HTTP parser
     - Malformed framing, oversized content and header budget rejections
   * - H2/H3
     - Cancellation/reset storms, sibling isolation and QPACK/HPACK bounds
   * - WebSocket
     - Unexpected subprotocol, mask direction, fragmentation and close control
   * - Proxy
     - Proxy credentials never forwarded; nested TLS unsupported and rejected
   * - Pool
     - Tenant/origin trust isolation and independent connection eviction
   * - Files
     - Caller directory policy and bounded IPC; no unadvertised durable write
   * - Workers
     - PID ownership, SIGKILL/reload recovery, no inherited live loop mutation

Run the PHPForge security matrix, host integration acceptance,
portable native probes and independent H2/H3 reference tests on the
*same immutable source*. A green single unit test is not an assurance
certificate. Consult :doc:`performance-qa-handbook-3.0` for source
provenance and :doc:`connection-policies-3.0` for detailed proxy
policy behavior.

Incident-response fundamentals
------------------------------

If peer verification is bypassed, secrets leak across origins, or
sessions survive past their owner, prioritize containment: stop
admitting new work, revoke credentials if exposed, record affected
source revision/driver, and preserve nonsecret diagnostics for
reproduction. Do not log raw client tokens to make debugging easier.

The source API remains under development; perform a final threat and
release-risk review before declaring 3.0 production certified.


Practical SSRF policy: what is rejected and when
------------------------------------------------

The optional ``OutboundAddressPolicy`` rejects private, reserved,
loopback, multicast and other non-global addresses. Use this when
an application accepts remote hostnames or URLs from customers.
It deliberately rejects even ``127.0.0.1``; use a separate trusted
internal-service policy when loopback access is an explicit requirement.

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Runwire\Network\DialOptions;
   use Infocyph\Runwire\Network\OutboundAddressPolicy;

   $policy = new OutboundAddressPolicy();
   $policy->assertCandidate('8.8.8.8');

   try {
       $policy->assertCandidate('127.0.0.1');
       throw new RuntimeException('Forbidden private destination accepted');
   } catch (RuntimeException $error) {
       // Expected for non-public input: reject the requested destination.
   }

   $dialOptions = new DialOptions(
       connectTimeoutSeconds: 3.0,
       addressPolicy: $policy,
   );

``DialOptions::addressPolicy`` enforces the candidate restriction during
outbound TCP connection attempts and verifies the kernel-reported
**connected peer** before handing the socket to application code.
Checking only the hostname or a cached DNS answer would be insufficient
against rebinding and cross-network redirects. The above example is a
policy-construction check, not an actual network connection or proof of
the target's legitimacy. Inspect ``tests/OutboundAddressPolicyTest.php``
for IPv4, IPv6, mapped-IPv6 and hostile proxy failure cases.

**When not to use it:** internal service discovery often intentionally
needs private IPs. That requires a separate deliberately scoped policy
with tenant authorization, rather than disabling validation globally.


Verified TLS example and failure decisions
------------------------------------------

TLS identity is the **original hostname**, not whichever numeric
address Happy Eyeballs selected. A secure client must verify both
certificate chain and peer name; ALPN chooses the allowed application
protocol. Do not set ``verify_peer`` to false to work around a wrong
certificate.

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Runwire\Network\DialOptions;
   use Infocyph\Runwire\Network\TlsClientOptions;

   $tls = new TlsClientOptions(
       peerName: 'example.com',
       alpnProtocols: ['h2', 'http/1.1'],
       handshakeTimeoutSeconds: 5.0,
   );
   $dial = new DialOptions(
       connectTimeoutSeconds: 5.0,
       tls: $tls,
   );

This builds a strict policy without opening a socket. Configure the
origin's trusted CA where required; the system trust store must be
available. A wrong SAN, missing OpenSSL, ALPN mismatch or expired
certificate is a connection failure, **not** permission to switch to
plaintext. The complete network tests are
``tests/TlsClientDialerTest.php`` and
``tests/VerifiedOriginalTargetTlsInteropTest.php``.

