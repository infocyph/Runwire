# Runwire 2.2 — real hosted environment acceptance

### R6.10 accepted — verified six-host real process matrix (2026-10-22)

**Immutable source/CI:** Runwire PR #9 source `f9ffedbc2c9ac6ef4e54e0a0d7daec86c29bb556`, [Real Hosted Runtime Acceptance #39828337713](https://github.com/infocyph/Runwire/actions/runs/39828337713), all six real jobs **SUCCESS** across PHP 8.4 and 8.5:

| Runwire-only real host | PHP 8.4 | PHP 8.5 | Verified ownership |
| --- | --- | --- | --- |
| FrankenPHP | PASS | PASS | Real Caddy/FrankenPHP worker HTTP, same process PID, increasing worker-local request sequence, fresh metadata |
| RoadRunner 3.0.1 with Composer-installed `roadrunner/http` 4.x | PASS | PASS | Real Go RoadRunner host HTTP, same PHP worker PID and increasing sequence, correct URL/method |
| PHP-FPM through Docker + independent FastCGI client | PASS | PASS | Real FPM SAPI `fpm-fcgi`, correct request metadata/HTTP response, PHP request-local counter resets on each request |

All fixtures live under `tests/fixtures/*-live/` in Runwire only; PHPForge, Foundation and Infbyte are **not** release consumers or dependencies of host acceptance. The current [Release Candidate workflow](https://github.com/infocyph/Runwire/actions/runs/39828337707) independently checked package contents / standalone Composer package installation under PHP 8.4 and 8.5. Previous RoadRunner startup failures were test harness relative-command resolution (fixed by absolute worker script path), not grounds for relaxing assertions. No simulated production host stands in for these rows.

**Decision:** **R6.10 DONE** (9/10 R6 tasks), 74/95 canonical checked, seven R5 deferred; **74/88 in scope (84.1%), 14 active open**. This **does not** satisfy R6.02 concurrent multi-protocol rolling recycle, R4 sustained stress or R8.05/R9 final-source certification. Current source QA still requires a fully green final exact-head run; older H1 release certification `145b52b6` is not final-head proof. No merge/tag/publish or other repository writes.
