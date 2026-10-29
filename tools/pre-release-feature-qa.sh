#!/usr/bin/env bash
# Check current public Runwire 3.0 capability claims without release-plan dependencies.
set -euo pipefail
catalog=docs/features.rst
index=docs/index.rst
test -s "$catalog" && test -s "$index"
for claim in   'U17 final certification pending'   'Full U15 release-performance soak remains OPEN'   'Final fuzz/security QA OPEN'   'Full cross-PHP parity OPEN'   'U16.12 and U17.01 are not yet complete'   'U15 full 1800s release soak remains open'   'autoscale policy and pure'; do
  if grep -Fq -- "$claim" "$catalog" docs/performance-security-3.0.rst docs/host-compatibility-3.0.rst docs/migration-3.0.rst docs/compatibility-audit-3.0.rst docs/protocols-3.0.rst; then
    echo "Stale release claim: $claim" >&2
    exit 1
  fi
done
grep -Fq 'WebTransport is not included in Runwire 3.0' "$catalog"
grep -Fq 'Nested TLS over an HTTPS proxy is not yet supported' "$catalog"
grep -Fq '1800-second mixed' "$catalog"
grep -Fq 'Runwire 3.0' "$index"
echo "Runwire 3.0 public feature claims verified"
