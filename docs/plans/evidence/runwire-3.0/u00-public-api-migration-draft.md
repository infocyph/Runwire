# Runwire 3.0 — U00 proposed breaking API migration (draft contract)

**Status:** Pre-implementation API-design document only. These are **illustrative target signatures**, not calls currently available in Runwire. Final signatures must be frozen before changing the public classes and reflected against the immutable 2.1.2 API manifest. Source: [published 2.1.2](https://github.com/infocyph/Runwire/tree/2.1.2/src).

## Public-contract migration matrix

| 2.1.2 actual interface | 3.0 proposed change | Compatibility classification |
| --- | --- | --- |
| `new Connection(LoopInterface $loop, mixed $stream, ConnectionLimits $limits = ...)` | `ConnectionFactory::adopt($stream, $loop, $owner, $limits)` after socket admission and verification | **Breaking** direct construction and factory-only socket ownership |
| `new AsyncConnection(Connection $connection, CoroutineScope $scope)` | `$scope->adoptConnection($connection)` or `$scope->dial($uri, $options)` | **Breaking** manually wrapped callbacks; one coroutine/transport owner |
| `Network\TlsOptions` with server certificate and `verify_peer=false` default | `ServerTlsOptions` for listeners, `ClientTlsOptions` for dialing; client certificate and hostname verification enabled | **Breaking** if existing server constructor renamed; never reuse insecure server defaults for outbound |
| `LoopInterface::{onReadable,onWritable,delay,repeat,defer,cancel,now,run,stop}` | Retain existing interface initially; add optional capability/executor interfaces rather than forcing all custom backends to implement new abstracts | **Avoidable breaking change**; only extend interface if backend tests justify |
| `RuntimeContext::__construct(driver, mode, workerSlot, generation, pid, persistent, concurrent, ownsListener, ownsEventLoop, ownsWorkerPool, capabilities, metrics)` | Dedicated runtime context factory + explicit worker/resource ownership accessor; leave direct constructor until migration is proven | **Potentially breaking** positional/manual instantiation |
| `CoroutineScope::{waitReadable,waitWritable,withDeadline,spawn,close}` | Add `dial`, `adoptConnection`, `file` operations to scope; no hidden blocking or different task cancellation semantics | **Potential behavior break** when scope closure now releases all owned I/O |
| `RuntimeOptions` fixed readonly constructor containing driver, opcache, lifecycle, admission, host settings | Add explicit network/async file/autoscale policy through immutable nested options or builder | **Breaking if positional args change**; document every parameter |
| `Runtime::create()->listen()->run()` | Keep high-level server ergonomics whenever possible, enabling new client I/O and common resource ownership behind the scenes | **Should remain compatible** except deliberately documented lifecycle changes |
| Existing `ConnectionState` `OPEN`, `DRAINING`, `CLOSED` | Maintain public terminal state semantics where possible; track pending/dial states in separate internal type and read/write EOF independently | **Potential enum/method break**, preserve simple existing observers |

## Concrete before/after illustrations (NOT runnable 3.0 code)

### A. Accepted or manually created stream

```php
// Runwire 2.1.2 (existing public constructor).
$connection = new Connection($loop, $stream, $limits);

// Runwire 3.0 proposal; factory must own verification, watchers and limits.
$connection = $factory->adopt($stream, loop: $loop, owner: $scope, limits: $limits);
```

### B. Coroutine connection handoff

```php
// Runwire 2.1.2 (existing public wrapper).
$async = new AsyncConnection($connection, $scope);
$result = $async->receive();

// Runwire 3.0 proposal; no competing callback owner.
$async = $scope->adoptConnection($connection);
$result = $async->receive();
```

### C. Outgoing TLS

```php
// Runwire 2.1.2 had no built-in complete outbound dialer.
// Typical external client calls are not part of Runwire's public API.

// Runwire 3.0 proposal (TLS verifies remote certificate + hostname).
$client = $scope->dial('tls://example.org:443', ClientTlsOptions::secureDefault());
```

### D. Runtime ownership

```php
// Runwire 2.1.2 high-level server API remains the normal use.
Runtime::create()->listen($server)->run();

// Runwire 3.0 proposal: runtime-owned network/filesystem service can be
// obtained from its worker/request context, with explicit capability checks.
$scope = $runtimeContext->scope();
$client = $scope->dial('tcp://127.0.0.1:9000');
```

## Migration policy / non-goals

- Publish a full **public reflected signature diff** between 2.1.2 and 3.0: removed symbols, renamed parameters, changed return types, enum cases, altered defaults, exceptions and new ownership/closure semantics.
- Avoid wrappers for legacy behavior that keep two different socket owners or unsafe TLS defaults.
- Legacy server code should remain source-compatible when doing so costs nothing; **breaking changes are allowed but not required for every type**.
- No Foundation/Infbyte/Rust Runwire-Core/Cells integration, package dependency or release gate.
- Per-host semantics remain truthful; a PHP-FPM request does not own its host worker pool, and Swoole cannot receive an alternate nested reactor.
- Update executable examples and Sphinx migration pages as implementation lands. **This draft is a design handoff; no API has yet changed.**
