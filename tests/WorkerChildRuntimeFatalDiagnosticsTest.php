<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Exception\ApplicationStartupException;
use Infocyph\Runwire\Runtime\Enum\ApplicationStartupPhase;
use Infocyph\Runwire\Supervisor\Internal\WorkerChildRuntime;
use Infocyph\Runwire\Supervisor\WorkerGroup;
use LogicException;
use RuntimeException;

it('reports safe worker-fatal cause metadata without changing exit semantics', function (bool $warmup, int $expectedExitCode, bool $anonymous): void {
    $log = tempnam(sys_get_temp_dir(), 'runwire-fatal-log-');
    if ($log === false) {
        throw new RuntimeException('Unable to allocate worker diagnostic log.');
    }

    try {
        $group = WorkerGroup::callbacks(
            name: 'fatal-diagnostic',
            count: 1,
            factory: static function () use ($warmup, $anonymous): void {
                $previous = new LogicException('private-upstream-credential');
                $failure = $anonymous
                    ? new class ('private-request-body', 0, $previous) extends RuntimeException {}
                : new RuntimeException('private-request-body', 0, $previous);

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
            $readyStream = fopen('php://temp', 'w+');
            if ($readyStream === false) {
                throw new RuntimeException('Unable to open worker readiness fixture.');
            }
            WorkerChildRuntime::run($group, 0, 1, $readyStream);
        }

        $status = 0;
        $waitedPid = pcntl_waitpid($pid, $status);
        /** @var int $status */
        expect($waitedPid)->toBe($pid)
            ->and(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe($expectedExitCode);

        $record = file_get_contents($log);
        if (!is_string($record)) {
            throw new RuntimeException('Unable to read worker fatal diagnostics.');
        }
        expect($record)
            ->toContain('Runwire worker fatal: depth=0 type=')
            ->toContain('file=WorkerChildRuntimeFatalDiagnosticsTest.php')
            ->toContain('type=' . LogicException::class)
            ->not->toContain('private-upstream-credential')
            ->not->toContain('private-request-body')
            ->not->toContain(__DIR__ . DIRECTORY_SEPARATOR);

        $entries = preg_match_all('/Runwire worker fatal: depth=\d+/', $record);
        expect($entries)->toBe($warmup ? 3 : 2);

        $completeEntries = preg_match_all(
            '/Runwire worker fatal: depth=\d+ type=[^\s\x00]+ file=WorkerChildRuntimeFatalDiagnosticsTest\.php line=\d+/',
            $record,
        );
        expect($completeEntries)->toBe($entries)
            ->and($record)->toContain('type=' . ($anonymous ? 'RuntimeException@anonymous' : RuntimeException::class) . ' file=');
    } finally {
        unlink($log);
    }
})->with([
    'application fatal preserves exit 70' => [false, 70, false],
    'warmup failure preserves exit 78' => [true, 78, false],
    'anonymous application fatal preserves metadata and exit 70' => [false, 70, true],
    'anonymous warmup cause preserves metadata and exit 78' => [true, 78, true],
]);
