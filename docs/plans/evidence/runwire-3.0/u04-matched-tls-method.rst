Matched TLS RPS, latency and RSS certification
===============================================

Use RUNWIRE_BENCH_TLS=1 bash benchmarks/matched_release_certification.sh
<2.1.2-checkout> <3.0-checkout> <evidence-directory>.

The candidate-owned TLS HTTP/1.1 server fixture loads the installed Composer
autoloader of each baseline/candidate tree and uses a temporary SAN=localhost
certificate. The shared client driver verifies the trust chain and hostname.
Both servers run on the same pinned physical CPU with a separate generator
core. The original release comparator runs five alternating 180-second trials
per population, checks correct replies, CPU, p99, and process-tree RSS.
A baseline checkout is not modified, and the script deletes its TLS private
key at exit. An ordinary green PR benchmark run is NOT matched TLS
certification; U04.08 is OPEN until full paired outputs meet every gate.
