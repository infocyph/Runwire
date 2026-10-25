# U08.04 / legacy R4.02 — matched HPACK workload profile

**Published baseline:** Runwire 2.1.2 immutable commit `7e9bf20ad0737a187dfed77b723791b141b36378`.
**Development candidate:** Runwire 3.0 draft PR #9 commit `c56090b50c582639987093ca975904f3a5ce9925` (W103).
**Runner:** the existing Benchmarks protocol-core jobs, one on PHP 8.4 and one on PHP 8.5, not duplicate PR/push jobs.
**Method:** identical `benchmarks/u08_hpack_source_profile.php` against each checkout's `src/`, 64 warmup operations and **5 independent timed samples of 320 calls each per workload**. Samples report median elapsed nanoseconds per operation and trial coefficient of variation (CV). Candidate/baseline ratio **above 1.0 means slower**. The source comparator is pinned via Actions checkout; raw and computed JSON are archived.

| Workload | PHP 8.4 baseline → candidate (ns/op; ratio) | PHP 8.5 baseline → candidate (ns/op; ratio) |
| --- | --- | --- |
| HPACK continuation integer (16,384 / PHP_INT_MAX) | 946.21 → 960.73 (**1.0153×**) | 681.62 → 685.79 (**1.0061×**) |
| Bounded Huffman decode | 75,356.56 → 74,534.76 (**0.9891×**) | 51,233.40 → 52,697.11 (**1.0286×**) |
| HTTP/2 request-header validation | 3,711.16 → 3,672.20 (**0.9895×**) | 2,666.56 → 2,693.10 (**1.0100×**) |
| Rotating HPACK encode/decode + HEADERS frame write | 30,456.10 → 30,819.98 (**1.0119×**) | 21,812.54 → 22,405.28 (**1.0272×**) |

**Complete archived raw profiles / exact-source comparisons:**
- [PHP 8.4 protocol-core artifact](https://github.com/infocyph/Runwire/actions/runs/40168119709/artifacts/12589100756): `u08-hpack-php-8.4/{baseline,candidate,comparison}.json`.
- [PHP 8.5 protocol-core artifact](https://github.com/infocyph/Runwire/actions/runs/40168119709/artifacts/12589840441): `u08-hpack-php-8.5/{baseline,candidate,comparison}.json`.
- [W103 Benchmarks workflow](https://github.com/infocyph/Runwire/actions/runs/40168119709).

**Precision and disposition:** PHP 8.4 candidate integer CV = **3.371%** and PHP 8.5 baseline integer CV = **2.545%**, exceeding the stricter release-performance 2.5% variability ceiling. All four workloads are *quantified* for old R4.02 before/after acceptance, but no verified performance improvement, regression-free release certification, or statistically significant difference is claimed. Some timings are faster, others slower, and workflow noise could dominate 1–3% ratios. U08.04 is a development **profiling** task; U08.08/U08.09, the full U15 matched sustained/performance matrix, and U17 frozen-release certification remain independent OPEN gates.

**Functional validation:** W102/W103 built on existing PHPBench and added independent 256-roundtrip never-index/table-boundness correctness tests; W103's full PHPForge/Sphinx, source, host, independent protocol and benchmark workflows are required to pass **before** U08.04 is marked accepted.
