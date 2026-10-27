#!/usr/bin/env bash
# Source-only gate for all 20 unfinished 2.2 -> 3.0 carry-forward mappings.
set -euo pipefail
plan="docs/plans/runwire-3.0-unified-runtime-development-plan.md"
test -s "$plan"
expected=(R0.06 R0.07 R1.08 R3.09 R4.02 R4.04 R4.09 R5.02 R5.03 R5.04 R5.05 R5.06 R5.07 R5.08 R6.02 R8.05 R8.10 R9.06 R9.07 R9.09)
declare -A found=()
accepted=0
open=0
while IFS='|' read -r _ id targets status _; do
  id="${id//[[:space:]]/}"
  [[ "$id" =~ ^R[0-9]+\.[0-9]{2}$ ]] || continue
  if [[ -n "${found[$id]:-}" ]]; then
    echo "Duplicate old 2.2 crosswalk ID: $id" >&2
    exit 1
  fi
  found[$id]=1
  mapfile -t mapped < <(grep -oE 'U[0-9]{2}\.[0-9]{2}' <<< "$targets")
  if (( ${#mapped[@]} == 0 )); then
    echo "Unmapped historical task: $id" >&2
    exit 1
  fi
  all_accepted=true
  for item in "${mapped[@]}"; do
    if grep -Fq -- "- [x] **$item**" "$plan"; then
      continue
    fi
    if grep -Fq -- "- [ ] **$item**" "$plan"; then
      all_accepted=false
      continue
    fi
    echo "Missing canonical task $item for $id" >&2
    exit 1
  done
  if "$all_accepted"; then
    [[ "$status" == *ACCEPTED* ]] || {
      echo "Stale crosswalk: $id mapped target accepted but old row is OPEN" >&2
      exit 1
    }
    accepted=$((accepted + 1))
  else
    [[ "$status" == *OPEN* ]] || {
      echo "False crosswalk acceptance for $id: canonical mapped task OPEN" >&2
      exit 1
    }
    open=$((open + 1))
  fi
done < <(sed -n '/^## Complete leftover carry-forward/,/^## Sphinx \/ Read the Docs/p' "$plan" | grep -E '^\| R[0-9]+\.[0-9]{2} \|')
for id in "${expected[@]}"; do
  [[ -n "${found[$id]:-}" ]] || {
    echo "Missing historical carry-forward mapping: $id" >&2
    exit 1
  }
done
test "${#found[@]}" -eq 20
test "$accepted" -eq 15
test "$open" -eq 5
printf 'Carry-forward mappings verified: total=%d accepted=%d awaiting-U17=%d\n' "${#found[@]}" "$accepted" "$open"
