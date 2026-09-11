# Runwire 1.0 — Runtime Drivers & OPcache Addendum

**Status:** normative addendum to `runwire-1.0-foundation-3-launch-plan.md`  
**Target:** Runwire 1.0 / Foundation 3 launch  
**Branch:** `runwire-1.0/foundation-3-launch`

> Runwire exposes one application-facing runtime contract while allowing the host execution engine to be selected explicitly. Runwire-native is the first-party server engine; FPM, FrankenPHP, Swoole and RoadRunner are host-runtime drivers. OPcache is an orthogonal accelerator capability, not a runtime driver.

---

## 1. Runtime selection model

Runwire must support these runtime driver values:

```text
auto
native
fpm
frankenphp
swoole
roadrunner
```

Optional future values may be added without changing the application-facing request/lifecycle contract.

### Meaning

- `native` — Runwire owns listener sockets, event loop, HTTP wire transport, worker supervision and process lifecycle using native PHP/OS facilities.
- `fpm` — PHP-FPM owns FastCGI/process-pool/request dispatch; Runwire operates as a request-bound runtime/lifecycle adapter and must not start a competing listener/event loop/supervisor.
- `frankenphp` — FrankenPHP owns its server/thread/worker runtime; Runwire adapts Foundation/Webrick execution to classic or worker mode and preserves request cleanup/isolation.
- `swoole` — Swoole/OpenSwoole owns its event loop, server sockets and worker topology; Runwire registers/adapts lifecycle and request callbacks instead of nesting the Runwire native loop.
- `roadrunner` — RoadRunner owns the external application server and worker management; Runwire adapts the PHP worker lifecycle/request transport and must not create a second HTTP listener/supervisor.
- `auto` — detect the active/available host deterministically and select the safest supported driver according to the precedence policy below.

Runwire must not pretend these engines have identical capabilities. Each driver exposes a capability snapshot.

---

## 2. OPcache is orthogonal

Do **not** add `opcache` to the runtime-driver enum.

OPcache is an execution accelerator that can be enabled with any compatible runtime.

Expose a separate policy:

```text
auto
on
off
required
```

Recommended configuration shape:

```php
$runtime = Runtime::create(
    driver: RuntimeDriver::AUTO,
    opcache: OpcacheMode::AUTO,
);
```

or equivalent immutable options.

Semantics:

- `auto` — use OPcache when the host PHP configuration already enables it; never fail solely because it is absent.
- `on` — request/recommend enabled operation but report clearly if the active SAPI cannot enable it at runtime; do not silently claim success.
- `off` — Runwire does not require/use OPcache-specific optimization hooks; it must not mutate unrelated host configuration globally.
- `required` — fail during runtime validation/boot when OPcache is unavailable or disabled for the selected SAPI.

Important PHP constraint: OPcache/CLI enablement is primarily `php.ini`/SAPI configuration. Runwire must validate capability, not pretend it can always turn `opcache.enable` or `opcache.enable_cli` on from application code.

For CLI-oriented `native`, `swoole`, and typical RoadRunner PHP workers, documentation must call out `opcache.enable_cli=1` where OPcache is desired. FPM/FrankenPHP follow their host PHP configuration.

---

## 3. Public API direction

Keep runtime selection small and explicit.

Preferred direction:

```php
$runtime = Runtime::create(
    driver: RuntimeDriver::FRANKENPHP,
    opcache: OpcacheMode::AUTO,
);

$runtime->serve($handler);
```

Equivalent config-array construction may exist for framework adapters, but the core API should remain typed.

Foundation-facing configuration can map directly to this model:

```php
'runwire' => [
    'runtime' => 'auto',
    'opcache' => 'auto',
];
```

CLI/environment examples:

```bash
php foundation serve --runtime=native
php foundation serve --runtime=frankenphp
php foundation serve --runtime=swoole
php foundation serve --runtime=roadrunner
```

FPM is normally host-launched rather than started by `foundation serve`; Foundation/Runwire should detect or select `fpm` while executing inside FPM instead of spawning an FPM daemon from the application process.

Allow a trusted config/environment value such as:

```text
RUNWIRE_RUNTIME=auto|native|fpm|frankenphp|swoole|roadrunner
RUNWIRE_OPCACHE=auto|on|off|required
```

Exact Foundation environment naming remains Foundation-owned.

---

## 4. Driver contract

Introduce one narrow internal/public integration contract rather than branching through the whole codebase.

Conceptual API:

```php
interface RuntimeDriverInterface
{
    public function capabilities(): RuntimeCapabilities;

    public function validate(RuntimeOptions $options): void;

    public function run(RuntimeApplication $application): void;

    public function stop(): void;
}
```

Exact names may change, but the separation is required.

Common application contract should cover:

- startup/boot callback;
- one logical request/exchange callback;
- request/exchange cleanup in `finally`;
- worker/runtime shutdown callback;
- reload/drain signal when the host exposes it;
- health/status metadata where available.

Do not force host-specific request objects into the common application API. Normalize at the driver boundary.

---

## 5. Runtime capability model

Every driver must report capabilities instead of relying on runtime-name conditionals throughout consumers.

At minimum:

```text
persistent_process
persistent_application
owns_listener
owns_event_loop
owns_worker_pool
supports_fork
supports_signals
supports_async_io
supports_coroutines
supports_graceful_reload
supports_worker_recycle
supports_http2
supports_http3
supports_websocket
supports_opcache
supports_opcache_cli
```

Capabilities describe the active runtime, not marketing assumptions. Runtime probing must be deterministic and testable.

Foundation/Webrick should consume capabilities where behavior genuinely differs; they should not become large `switch ($runtime)` trees.

---

## 6. `auto` detection

`auto` must be conservative and deterministic.

Recommended detection order when already executing inside a host runtime:

```text
FrankenPHP host
    ↓
Swoole/OpenSwoole host
    ↓
RoadRunner worker host
    ↓
FPM/FastCGI
    ↓
Runwire native CLI eligibility
    ↓
unsupported / explicit failure
```

Do not select an installed extension merely because it exists. Detection must distinguish **available** from **currently hosted by**.

For an explicit `foundation serve --runtime=...`, explicit user selection overrides auto detection and capability validation must fail fast when the requested driver is unavailable.

Do not silently fall back from an explicitly requested runtime to another runtime in production.

---

## 7. Native driver

`native` is Runwire's full first-party server/runtime implementation from the canonical launch plan.

It owns:

- listener bind/accept;
- pure-PHP/select or optional event backend;
- HTTP/1.1 wire parser/serializer;
- connection state/backpressure;
- prefork/process supervisor where supported;
- wait/reap/signals/reload;
- generic supervised tasks;
- structured process execution.

On Unix, `pcntl`/`posix` unlock the full prefork/signal model. Capability detection must expose reduced behavior when unavailable instead of hiding it.

`native` must remain usable without Swoole, RoadRunner, FrankenPHP, or FPM.

---

## 8. FPM driver

FPM already owns process pools, graceful process management, UIDs/GIDs, FastCGI listeners and per-request dispatch. Runwire must not duplicate those responsibilities.

The FPM driver is intentionally thin:

```text
web server / FastCGI
        ↓
PHP-FPM
        ↓
Runwire FpmDriver
        ↓
Foundation/Webrick request execution
```

Requirements:

- one logical Runwire application execution per FPM request;
- no Runwire long-running event loop;
- no Runwire HTTP socket listener;
- no Runwire prefork worker supervisor;
- request cleanup always executes;
- Runwire process-execution APIs remain independently usable where policy permits;
- transport/runtime capability snapshot clearly marks persistent application state as false for ordinary FPM request mode.

This lets an application use Runwire APIs consistently without requiring the Runwire-native server.

---

## 9. FrankenPHP driver

Support both host shapes when detectable:

```text
classic mode
worker mode
```

### Classic mode

Treat request/application persistence similarly to a request-bound SAPI integration.

### Worker mode

FrankenPHP keeps application code resident and repeatedly invokes a worker handler. Runwire must therefore enforce the persistent-runtime contract:

- boot long-lived application state once where appropriate;
- begin a fresh Foundation/Webrick execution scope per request;
- cleanup request-scoped state in `finally`;
- never retain request/auth/session/DB execution state across requests;
- integrate worker restart/reload hooks when exposed;
- do not start a nested Runwire event loop or process pool;
- preserve host-owned threads/workers.

Runwire must document that globals, statics and in-memory state can persist in worker mode and therefore application/framework reset discipline is mandatory.

FrankenPHP remains an optional host integration; Runwire must not depend on the FrankenPHP binary for normal installation.

---

## 10. Swoole/OpenSwoole driver

Swoole/OpenSwoole owns its server, event loop, workers and coroutine system. Runwire must adapt rather than compete.

Requirements:

- bind Runwire application callback to the host HTTP/request event;
- normalize native request/response into the common Runwire transport contract;
- map start/worker-start/worker-stop/shutdown/reload lifecycle events;
- preserve fresh application execution scope per logical request;
- never run the Runwire native `stream_select()` loop inside the Swoole server loop;
- never create a parallel Runwire prefork supervisor for host HTTP workers;
- expose coroutine/async capability without requiring Foundation to become coroutine-coupled;
- document and test persistent static/global state isolation.

Support may target Swoole/OpenSwoole through capability adapters; exact package/extension compatibility should be isolated from the core runtime API.

---

## 11. RoadRunner driver

RoadRunner owns the external server/process manager and dispatches work to PHP workers.

Required architecture:

```text
RoadRunner server
      ↓
RR PHP worker transport
      ↓
Runwire RoadRunnerDriver
      ↓
Foundation/Webrick execution
```

Requirements:

- use the official RoadRunner PHP worker/protocol ecosystem where practical rather than cloning Goridge/worker transport;
- one fresh application execution scope per request/job exchange;
- application/container reuse only where Foundation's persistent-runtime contract permits it;
- cleanup in `finally`;
- map worker stop/recycle/reset behavior into generic Runwire lifecycle signals;
- do not bind a second HTTP listener;
- do not fork a second HTTP worker pool underneath RoadRunner;
- keep RoadRunner packages optional/suggested unless selected adapter code intrinsically requires a separate integration package.

---

## 12. Unified option passing

The user's selected driver should change **hosting behavior**, not application APIs.

Example:

```php
Runwire::boot([
    'runtime' => 'roadrunner',
    'opcache' => 'required',
    'workers' => 8,
    'max_requests' => 10_000,
]);
```

But options must be partitioned by ownership:

### Portable options

- request timeout;
- max requests before recycle preference;
- application drain timeout;
- status/diagnostics policy;
- OPcache requirement;
- common transport bounds that Runwire can enforce at its boundary.

### Native-only options

- listener backlog;
- Runwire event-loop backend;
- native worker count;
- Runwire restart budget;
- Runwire socket high/low watermarks;
- native prefork mode.

### Host-driver options

Host-specific settings must live under a namespaced section rather than polluting the common option namespace:

```php
[
    'runtime' => 'frankenphp',
    'frankenphp' => [
        // adapter-specific knobs only
    ],
    'swoole' => [
        // adapter-specific knobs only
    ],
    'roadrunner' => [
        // adapter-specific knobs only
    ],
]
```

Runwire must reject irrelevant/unknown strict-production options rather than silently ignoring a `native` setting while running under another host.

Do not copy every host server's complete configuration DSL into Runwire. Expose only integration-relevant options; native host configuration remains authoritative for host-owned mechanics.

---

## 13. Foundation integration

Foundation should expose one runtime selector and keep its application graph independent of the selected host.

Target model:

```text
Foundation application
        ↓
Webrick HTTP semantics
        ↓
Runwire Runtime
        ↓
selected driver
 ┌────────┬────────────┬────────┬────────────┬──────────┐
 native    FPM       FrankenPHP  Swoole     RoadRunner
```

Foundation owns:

- config/env/CLI selection;
- whether explicit runtime selection is allowed in production;
- release-generation mapping;
- application boot and execution scopes;
- process capability authorization;
- runtime-specific deployment documentation/defaults.

Runwire owns runtime detection/driver mechanics/capabilities.

Webrick should need only the Runwire transport/runtime adapter for the Foundation native path; host differences stay below that integration where possible.

---

## 14. Testing matrix

Runwire 1.0 release acceptance should add driver-specific tests.

### Common contract

- same application handler semantics across all available drivers;
- startup/request/shutdown ordering;
- guaranteed request cleanup;
- structured runtime capabilities;
- explicit unavailable-driver failure;
- explicit selection never silently falls back;
- auto detection is deterministic;
- unknown/irrelevant option rejection;
- OPcache `required` fails closed when unavailable.

### Persistent runtimes

For FrankenPHP worker mode, Swoole and RoadRunner:

- repeated requests do not retain prior request state;
- interleaved/concurrent execution follows driver-supported isolation semantics;
- DB/cache/network resources obey application execution ownership;
- memory growth/recycle behavior is bounded/measured;
- worker reload/recycle does not corrupt release/application state.

### FPM

- no persistent application/request state assumption;
- no native listener/event-loop/supervisor starts;
- request cleanup and process-execution APIs remain correct.

### Native

Keep the full native network/supervisor/backpressure/security acceptance from the canonical plan.

---

## 15. Benchmark matrix

Record separate measurements for:

```text
FPM
FrankenPHP classic
FrankenPHP worker
Swoole/OpenSwoole
RoadRunner
Runwire native/select
Runwire native/optional event backend
```

For each available environment measure:

- cold start/boot;
- warm request throughput;
- p50/p95/p99 latency;
- memory per worker/process/thread where measurable;
- persistent memory growth;
- request cleanup overhead;
- Runwire adapter overhead versus direct host-framework integration;
- OPcache on/off effect where the host permits a meaningful controlled comparison.

Do not combine these into one misleading headline benchmark. Attribute host runtime cost versus Runwire adapter cost.

---

## 16. Dependency policy

Core Runwire must remain installable for the native/FPM baseline without requiring all optional runtimes.

Recommended policy:

- Swoole/OpenSwoole: optional extension capability.
- FrankenPHP: optional host capability, no mandatory Composer dependency merely for detection.
- RoadRunner: optional suggested/reference worker package(s) where needed by the driver.
- OPcache: optional Zend extension/capability, not a Composer dependency.
- FPM: SAPI/host capability, not a Composer dependency.

The core package should fail only when the caller explicitly selects a runtime whose required host capability is unavailable.

---

## 17. Completion gate extension

Runwire 1.0/Foundation 3 launch additionally requires:

- [ ] runtime enum/config supports `auto`, `native`, `fpm`, `frankenphp`, `swoole`, `roadrunner`;
- [ ] OPcache is modeled separately as `auto|on|off|required`;
- [ ] capability detection distinguishes installed from actively hosted runtime;
- [ ] explicit runtime selection fails fast rather than silently falling back;
- [ ] native mode remains a complete first-party server implementation;
- [ ] host modes do not start competing event loops/listeners/process pools;
- [ ] FPM request-bound behavior is tested;
- [ ] FrankenPHP classic + worker-mode lifecycle is documented/tested where available;
- [ ] Swoole/OpenSwoole persistent lifecycle is documented/tested where available;
- [ ] RoadRunner worker lifecycle is documented/tested where available;
- [ ] persistent runtime state isolation passes across all persistent drivers;
- [ ] common option parsing and host-specific namespaced options are bounded/validated;
- [ ] benchmarks attribute Runwire adapter overhead separately for every supported host;
- [ ] Foundation can select the runtime through trusted config/CLI without changing Webrick application semantics.

This addendum is part of the Runwire 1.0 launch gate and must be reconciled into the consolidated canonical plan before release.
