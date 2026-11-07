# Runwire CI / Psalm remediation batches

## Baseline

- Base branch: `main`, commit `7034ff908058edf476e4918ff60902c4004e39bf`.
- Initial failing workflow: [Security & Standards #1889](https://github.com/infocyph/Runwire/actions/runs/42012987000), November 5, 2026.
- Two Psalm jobs (PHP 8.4, 8.5) report overlapping findings. The console prints 3,341 finding lines per job and notes additional truncated results; do not claim this is the complete unique count.
- One PHP 8.4 prefer-stable QA failure: `tests/SupervisorLifecycleTest.php:66` (two ready PIDs, expected three).
- Analyzer dependency: `infocyph/phpforge: dev-main@dev` resolved to `a6232b6` on the failed run, which uses Psalm 7.0.3.

## Execution rules

1. Target approximately **100 confirmed findings per batch**; one commit per batch, not one commit per file.
2. Count unique findings, not duplicated reports from PHP 8.4 and 8.5.
3. Make code fixes before using Psalm suppressions or a weaker analyzer configuration. Preserve runtime behavior, security checks and supported PHP versions.
4. Verify source locations and target declarations, then run PHP syntax, focused tests and full CI wherever available.
5. Record actual post-change Psalm deltas before declaring a batch verified.
6. Leave pull request unmerged and do not tag a release.

## Progress

| Batch | Finding class / scope | Source locations targeted | Status |
| --- | --- | ---: | --- |
| 1 | Add missing `#[\\Override]` to declared implementation methods (21 PHP files) | 100 of 144 | Verified: 100 removed; QA, Source Audit, docs and release checks passed; Psalm still fails on remaining findings |
| 2 | Finish remaining override findings (44) + explicit exception contracts (57, six existing files) | 101 target | Implemented; CI verification pending |
| 3+ | Further analyzer findings in 100-finding batches | TBD | Not started |

### Batch 1 verification

- The 100 source locations were matched to the named function declarations from the failed PHP 8.4 Psalm log.
- Only explicit `#[\\Override]` declarations were added; no method signatures or behavior were changed.
- Added 14 test-backed public implementation ledger dispositions to preserve Source Audit coverage; an additional request-ID generator regression test exercises the new documented contract.
- The first PR run exposed the migration-ledger requirement; Source Audit must be rechecked on the amended batch.
- First batch's next GitHub run verified the override count reduction from 144 to 44; all four QA lanes passed, Source Audit passed, but Psalm remains red on unrelated findings.
- No local Psalm or PHP syntax check has been run; GitHub PR CI remains the verification source.

### Batch 2 scope

- Add all 44 remaining `#[\\Override]` declarations reported by the next PHP 8.4 analyzer run.
- Document 57 explicit thrown-exception findings in existing source files, preserving the signatures and exception behavior.
- Update the 2.1.2 migration ledger with 12 newly touched published types, each mapped to existing regression tests.
- Confirm actual count reductions and PHP 8.4/8.5 QA on the new head before marking Batch 2 verified.
- Patch release/version changes are explicitly deferred until all remediation batches are complete.
- Track the observed delta and CI status after the PR workflow runs.
