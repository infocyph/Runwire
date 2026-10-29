<?php

declare(strict_types=1);

it('retains strict adaptive benchmark failure and prints server errors for diagnosis', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/benchmarks/adaptive_http12_certify.sh');
    expect($source)->toBeString()
        ->toContain('if ! php benchmarks/adaptive_http1_matrix.php')
        ->toContain('Adaptive HTTP/1.1 $mode trial $trial failed')
        ->toContain('tail -n 120 "$tmpdir/server.log" >&2')
        ->toContain('      exit 1')
        ->toContain('bash', 'set -euo pipefail');
});
