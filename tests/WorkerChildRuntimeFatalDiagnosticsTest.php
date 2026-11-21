<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\ApplicationStartupException;
use Infocyph\Runwire\Runtime\Enum\ApplicationStartupPhase;
use Infocyph\Runwire\Supervisor\Internal\WorkerChildRuntime;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('reports safe worker-fatal cause metadata without changing exit semantics', function (bool $warmup, int $expectedExitCode): void {
    $log = tempnam(sys_get_temp_dir(), 'runwire-fatal-log-');
    if ($log === false) {
        throw new RuntimeException('Unable to allocate worker diagnostic log.');
    }

    try {
        $group = WorkerGroup::callbacks(
            name: 'fatal-diagnostic',
            count: 1,
            factory: static function (WorkerContext $context) use ($warmup): void {
                $previous = new LogicException('private-upstream-credential');
                $failure = new RuntimeException('private-request-body', 0, $previous);
                throw $warmup
                    ? new ApplicationStartupException(ApplicationStartupPhase::WARMUP, $failure)
                    : $failure;
            },
        );

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork worker diagnostic fixture.');
        }

        if ($pid === 0) {
            ini_set('log_errors', '1');
            ini_set('error_log', $log);
            WorkerChildRuntime::run($group, 0, 1, null);
        }

        $status = 0;
        expect(pcntl_waitpid($pid, $status))->toBe($pid)
            ->and(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe($expectedExitCode);

        $record = file_get_contents($log);
        expect($record)->toBeString()
            ->toContain('Runwire worker fatal: depth=0 type=')
            ->toContain('file=WorkerChildRuntimeFatalDiagnosticsTest.php')
            ->toContain('type=' . LogicException::class)
            ->not->toContain('private-upstream-credential')
            ->not->toContain('private-request-body')
            ->not->toContain(realpath(__DIR__) . DIRECTORY_SEPARATOR);

        $entries = preg_match_all('/Runwire worker fatal: depth=\d+/', $record);
        expect($entries)->toBe($warmup ? 3 : 2);
    } finally {
        unlink($log);
    }
})->with([
    'application fatal preserves exit 70' => [false, 70],
    'warmup failure preserves exit 78' => [true, 78],
]);
