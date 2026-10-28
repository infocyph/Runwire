#!/usr/bin/env bash
# Audit actual 3.0 scope rather than accepting stale feature claims.
set -euo pipefail
plan=docs/plans/runwire-3.0-unified-runtime-development-plan.md
catalog=docs/features.rst
test -s "$plan" && test -s "$catalog"
total="$(grep -Ec '^- \[[x ]\] \*\*U[0-9]{2}\.[0-9]{2}\*\*' "$plan")"
open="$(grep -Ec '^- \[ \] \*\*U[0-9]{2}\.[0-9]{2}\*\*' "$plan" || true)"
test "$total" -eq 167
# The scoped hardening branch accepts all 167 canonical tasks;
# any reopened task must stop the claim until the evidence is reconciled.
test "$open" -eq 0
for blocked in \
  'U17 final certification pending' \
  'Full U15 release-performance soak remains OPEN' \
  'Final fuzz/security QA OPEN' \
  'Full cross-PHP parity OPEN' \
  'Full U15 release-performance soak remains OPEN' \
  'full mixed 1800-second soak, externally hosted' \
  'autoscale policy and pure' \
  'remain separate unimplemented feature gates'; do
  if grep -Fq -- "$blocked" "$catalog" docs/performance-security-3.0.rst; then
    echo "Stale release-capability assertion: $blocked" >&2
    exit 1
  fi
done
grep -Fq 'WebTransport is not included in Runwire 3.0' "$catalog"
grep -Fq 'Nested TLS over an HTTPS proxy is not yet supported' "$catalog"
grep -Fq 'no automatic cross-protocol H1/H2/H3' docs/performance-security-3.0.rst || \
  grep -Fq 'general automatic cross-protocol H1/H2/H3' docs/performance-security-3.0.rst
grep -Fq '1800-second mixed' "$catalog"
grep -Fq 'Final Release Certification' docs/index.rst || \
  grep -Fq 'release certification' docs/index.rst
echo "pre-release feature contract: 167 checked, 0 open, scoped limits preserved"
