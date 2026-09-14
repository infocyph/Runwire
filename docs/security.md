# Runtime Security and Production Hardening

This guide covers operational security for Runwire deployments. Vulnerability reporting and coordinated disclosure remain in [`SECURITY.md`](../SECURITY.md). Production topology and lifecycle operations remain in [`deployment.md`](deployment.md).

Runwire provides bounded runtime primitives; it is not an operating-system sandbox. Applications and deployment platforms remain responsible for least privilege, secrets, filesystem permissions, network policy, and process/container isolation.

## Least privilege

Prefer this deployment order:

```text
unprivileged service account
    ↓
reverse proxy / high port / socket activation / controlled port capability
    ↓
Runwire application
```

Do not start Runwire as root merely to bind ports 80 or 443. Prefer a reverse proxy, a high unprivileged port, socket activation, or a narrowly scoped platform capability.

When native prefork genuinely must start privileged, configure a worker `PrivilegeDropPolicy`. The worker transition is deliberately completed before application bootstrap and readiness:

```text
fork worker
→ resolve target passwd identity
→ initialize target supplementary groups
→ set target primary GID
→ set target UID
→ verify effective UID/GID
→ application bootstrap/warmup
→ ready
→ serve traffic
```

A partial identity transition is a startup failure. When native prefork is selected with effective UID `0` and no privilege-drop policy, the runtime selection includes a high-severity `SECURITY:` warning. Application traffic should not intentionally run as root.

Portable native mode does not own a prefork worker pool and therefore rejects worker privilege-drop configuration. Run the whole portable process as the intended unprivileged account instead.

## Persistent application state

Native Runwire, RoadRunner, FrankenPHP worker mode, and Swoole/OpenSwoole can keep application state alive across requests. Treat any mutable worker-lifetime state as persistent unless an adapter explicitly resets it.

Framework/application adapters should reset request-local state after every request, including failed, cancelled, and deadline-exceeded requests. Typical reset targets include:

- request-scoped container services;
- authenticated user / authorization context;
- tracing and correlation context;
- locale and timezone changes;
- temporary globals or static request state;
- ORM identity maps / unit-of-work state;
- open or failed transactions;
- framework request/event objects;
- response/body callbacks and temporary buffers.

`RequestContext::complete()` and application resetters are the Runwire-level cleanup boundary. Framework integrations must register their own resetters for framework-owned request-local state.

## Resource ceilings

Keep limits finite and reduce them to actual application requirements. Increasing every limit is not a hardening strategy.

Review at least:

- listener connection limits and accept batch sizes;
- connection receive/send high-water marks;
- idle and lifetime timeouts;
- HTTP/1.1 request line, header line, aggregate header, header count, body and keep-alive limits;
- HTTP/2 frame, stream, header block, control-frame and flow-control limits;
- HTTP/3 stream, field-section, QPACK, control-work and response-queue limits;
- response buffering ceilings;
- framed-protocol maximum frame sizes;
- UDP maximum datagram size and receive batch size;
- coroutine task, waiter, queue and backlog ceilings;
- ProcessRunner stdin/stdout/stderr, argv, environment and timeout limits.

Malicious peer-declared sizes must never cause memory growth proportional to an untrusted declared size before the applicable bound is enforced.

### Framed protocols

Every application-specific `FrameCodecInterface` implementation must enforce a finite maximum frame size. A delimiter-based codec must also bound data buffered while waiting for a delimiter.

### UDP

UDP handlers should keep callback work bounded and avoid performing unbounded synchronous work per datagram. Runwire bounds datagram size and receive batches; application work still needs a budget appropriate to the service.

## Worker recycling

Worker recycling is a reliability and isolation mechanism for long-lived runtimes. It can limit the impact of:

- gradual PHP memory growth;
- application leaks;
- stale persistent state;
- allocator fragmentation;
- long-lived resource degradation.

It is not a mitigation for native memory-corruption vulnerabilities.

For runtimes with worker replacement capability, configure request/lifetime/memory thresholds from measured production behavior rather than arbitrary small values.

### Memory-limit semantics

`WorkerRecyclePolicy::maxMemoryBytes` currently evaluates PHP allocator memory through `memory_get_usage(true)` and `memory_get_peak_usage(true)`.

It is **not** equivalent to:

- resident set size (RSS);
- cgroup/container memory usage;
- memory allocated only inside native extensions;
- kernel/socket buffers;
- total process footprint.

Use operating-system or container memory limits as the authoritative hard process-memory boundary.

### Portable native mode

Portable native is one process and has no internal replacement worker. Worker recycle thresholds therefore fail closed instead of being silently ignored.

If process-level recycling is required, use the external service manager or deployment platform, for example systemd, a container orchestrator, or another process supervisor. A distinct Runwire single-process retirement policy may be considered after 1.0; `WorkerRecyclePolicy` is intentionally not overloaded for this purpose.

## Portable native capability contract

Without PCNTL/POSIX prefork capability, native CLI uses the single-process portable runtime.

```text
workers 0 → one process
workers 1 → one process
workers > 1 → startup error

enabled worker recycle threshold → startup error
native control endpoint → startup error
development worker watcher → startup error
supervisor lifecycle listener → startup error
worker privilege drop → startup error

HTTP/3 configured without QUIC → startup error
HTTP/3 not configured → HTTP/1.1/HTTP/2 remain unaffected
```

Portable native remains suitable for ordinary HTTP, framed TCP/Unix, and UDP serving when their required platform capabilities exist. External supervision owns process restart/replacement.

## ProcessRunner security

`ProcessRunner` deliberately avoids a shell and executes a validated argv vector with `bypass_shell = true`. It also supports explicit bounds for arguments, environment, working directory, stdin, output, runtime and termination grace.

The 1.0 default keeps:

```php
allowedExecutables: null
```

This means any **absolute executable path** that otherwise passes command validation is permitted. It is an intentional usability default, not an application authorization policy.

For security-sensitive process execution, use an explicit allowlist:

```php
use Infocyph\Runwire\Process\ProcessPolicy;

$policy = new ProcessPolicy(
    allowedExecutables: [
        '/usr/bin/example',
    ],
    allowedEnvironmentKeys: [
        'LANG',
    ],
    allowedCwdRoots: [
        '/srv/application/jobs',
    ],
);
```

Never let untrusted input directly choose executable paths, unrestricted arguments, environment variable names/values, or working directories. Validate application-level arguments according to the called program's own grammar as well; shell avoidance does not make every target executable safe for arbitrary user-controlled arguments.

ProcessRunner does not require PCNTL. It requires these PHP process functions to remain available:

```text
proc_open
proc_get_status
proc_terminate
proc_close
```

## `disable_functions`

PHP `disable_functions` can reduce accidental access to unused process APIs, but it is not a security sandbox. Application code executing in the same PHP process still shares that process's privileges and accessible resources.

### Portable native example

When the application does not use the corresponding APIs, a portable CLI profile may disable shell-oriented helpers:

```ini
disable_functions = exec,passthru,shell_exec,system,popen,pcntl_exec
```

If the application uses `ProcessRunner`, retain:

```text
proc_open
proc_get_status
proc_terminate
proc_close
```

### Native prefork example

Do not disable the PCNTL/POSIX functions required by the native supervisor, signaling, or configured privilege dropping. You may still disable unused shell-oriented helpers such as:

```ini
disable_functions = exec,passthru,shell_exec,system,popen,pcntl_exec
```

Only use a profile after verifying the exact application and extension requirements.

### Host-owned runtimes

For FPM, RoadRunner, FrankenPHP, Swoole/OpenSwoole, or another host, start from that host's documented requirements. Do not copy the portable or prefork profile blindly; the host may use process or signal functions outside Runwire's direct control.

## systemd hardening

A systemd unit can add defense-in-depth controls. Suitable options depend on what the application actually needs:

```ini
User=runwire
Group=runwire
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
CapabilityBoundingSet=
AmbientCapabilities=
MemoryMax=1G
TasksMax=256
LimitNOFILE=65536
```

Do not enable controls blindly. Check interactions with:

- TLS certificate/private-key access;
- Unix socket directories and ownership;
- writable log/cache/upload directories;
- ProcessRunner executable access;
- temporary-file usage;
- privileged port binding.

If a narrowly scoped capability is genuinely required, grant only that capability rather than retaining root execution.

## Container hardening

Prefer:

- a non-root UID/GID;
- read-only root filesystem where practical;
- explicit writable mounts only where required;
- dropped Linux capabilities by default;
- CPU and memory limits;
- PID/task limits;
- appropriate seccomp, AppArmor, or SELinux policy;
- secrets supplied through the platform rather than baked into images.

These controls are outside Runwire itself and remain useful even when application-level limits are correctly configured.

## TLS and optional protocols

Explicit TLS configuration must fail if the required OpenSSL capability is unavailable; it must not silently downgrade to plaintext. Explicit HTTP/3 configuration similarly requires the supported QUIC capability and fails closed when it is absent.

Keep certificates and private keys readable only by the intended service identity. Treat QUIC/HTTP/3 as an optional capability, not a reason to weaken the baseline HTTP/1.1/HTTP/2 security posture.

## Release and incident posture

Security-sensitive runtime behavior should be certified on the exact release head. After a source/runtime change, rerun the relevant release gates rather than relying on evidence from an earlier commit.

For suspected vulnerabilities, follow [`SECURITY.md`](../SECURITY.md) and avoid public disclosure of exploit details before coordinated remediation.
