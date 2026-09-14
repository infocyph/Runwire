<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\ProcessStartException;
use Infocyph\Runwire\Process\Command;
use Infocyph\Runwire\Process\Enum\IoMode;
use Infocyph\Runwire\Process\Enum\OutputOverflowPolicy;
use Infocyph\Runwire\Process\Enum\TerminationReason;
use Infocyph\Runwire\Process\ProcessPolicy;
use Infocyph\Runwire\Process\ProcessRunner;

function processRunner(array $policy = []): ProcessRunner
{
    return new ProcessRunner(new ProcessPolicy(...$policy));
}

it('executes argv literally without a shell', function (): void {
    $result = processRunner()->run(Command::executable(PHP_BINARY, ['-r', 'echo $argv[1];', 'a;$(echo hacked)']));
    expect($result->successful())->toBeTrue()->and($result->stdout)->toBe('a;$(echo hacked)');
});

it('supports bounded string resource and producer stdin', function (): void {
    $runner = processRunner();
    $echo = static fn (mixed $input) => $runner->run(Command::executable(PHP_BINARY, ['-r', 'echo stream_get_contents(STDIN);'])->stdin($input))->stdout;
    $resource = fopen('php://temp', 'w+');
    expect($resource)->toBeResource();
    fwrite($resource, 'resource');
    rewind($resource);
    $chunks = ['pro', 'ducer', null];
    $producer = static function (int $maxBytes) use (&$chunks): ?string {
        $chunk = array_shift($chunks);

        return $chunk === null ? null : substr($chunk, 0, $maxBytes);
    };
    expect($echo('string'))->toBe('string')
        ->and($echo($resource))->toBe('resource')
        ->and($echo($producer))->toBe('producer');
    fclose($resource);
});

it('drains stdout and stderr concurrently without deadlock', function (): void {
    $bytes = 262_144;
    $command = Command::executable(PHP_BINARY, ['-r', sprintf('$s=str_repeat("x",%d);fwrite(STDOUT,$s);fwrite(STDERR,$s);', $bytes)])->maxOutputBytes($bytes * 2);
    $result = processRunner()->run($command);
    expect(strlen($result->stdout))->toBe($bytes)
        ->and(strlen($result->stderr))->toBe($bytes)
        ->and($result->stdoutBytes)->toBe($bytes)
        ->and($result->stderrBytes)->toBe($bytes);
});

it('streams output without retaining capture buffers', function (): void {
    $stdout = '';
    $stderr = '';
    $command = Command::executable(PHP_BINARY, ['-r', 'fwrite(STDOUT,"out");fwrite(STDERR,"err");'])->output(IoMode::STREAM, IoMode::STREAM);
    $result = processRunner()->run(
        $command,
        static function (string $chunk) use (&$stdout): void { $stdout .= $chunk; },
        static function (string $chunk) use (&$stderr): void { $stderr .= $chunk; },
    );
    expect($stdout)->toBe('out')->and($stderr)->toBe('err')->and($result->stdout)->toBe('')->and($result->stderr)->toBe('');
});

it('truncates or terminates explicitly when output exceeds its ceiling', function (): void {
    $truncate = Command::executable(PHP_BINARY, ['-r', 'echo str_repeat("x",10000);fwrite(STDERR,str_repeat("y",10000));'])
        ->maxOutputBytes(1_000)->overflowPolicy(OutputOverflowPolicy::TRUNCATE);
    $truncated = processRunner()->run($truncate);
    expect(strlen($truncated->stdout) + strlen($truncated->stderr))->toBe(1_000)
        ->and($truncated->outputLimitExceeded())->toBeTrue()
        ->and($truncated->successful())->toBeTrue();

    $terminate = Command::executable(PHP_BINARY, ['-r', 'while(true){echo str_repeat("x",4096);}'])
        ->maxOutputBytes(10_000)->timeout(5.0)->terminationGrace(0.05);
    expect(processRunner()->run($terminate)->terminationReason)->toBe(TerminationReason::OUTPUT_LIMIT);
});

it('escalates a timed out process from TERM to KILL', function (): void {
    $command = Command::executable(PHP_BINARY, ['-r', 'pcntl_async_signals(true);pcntl_signal(SIGTERM,SIG_IGN);while(true){usleep(100000);}'])
        ->timeout(0.15)->terminationGrace(0.05);
    $result = processRunner()->run($command);
    expect($result->timedOut())->toBeTrue()->and($result->terminationSignal)->toBe(SIGKILL);
});

it('enforces executable environment cwd and command resource policy', function (): void {
    $root = sys_get_temp_dir();
    $runner = processRunner([
        'allowedExecutables' => [PHP_BINARY],
        'allowedEnvironmentKeys' => ['RUNWIRE_TEST'],
        'allowedCwdRoots' => [$root],
        'maxTerminationGraceSeconds' => 0.5,
    ]);
    $command = Command::executable(PHP_BINARY, ['-r', 'echo getenv("RUNWIRE_TEST")."|".getcwd();'])
        ->environment(['RUNWIRE_TEST' => 'yes'])
        ->cwd($root)
        ->terminationGrace(0.5);
    expect($runner->run($command)->stdout)->toBe('yes|' . realpath($root));
    expect(fn () => $runner->run($command->environment(['DENIED' => 'x'])))->toThrow(ProcessStartException::class)
        ->and(fn () => $runner->run($command->terminationGrace(1.0)))->toThrow(ProcessStartException::class);
});

it('normalizes exit status and fails before spawn for invalid executable or oversized stdin', function (): void {
    $runner = processRunner(['maxStdinBytes' => 4]);
    expect($runner->run(Command::executable(PHP_BINARY, ['-r', 'exit(7);']))->exitCode)->toBe(7)
        ->and(fn () => $runner->run(Command::executable('/definitely/missing/runwire-binary')))->toThrow(ProcessStartException::class)
        ->and(fn () => $runner->run(Command::executable(PHP_BINARY)->stdin('12345')))->toThrow(ProcessStartException::class);
});

it('rejects non-stream stdin resources before spawning the configured command', function (): void {
    $fixture = proc_open(
        [PHP_BINARY, '-r', 'usleep(500000);'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    expect($fixture)->toBeResource();

    try {
        expect(fn() => Command::executable(PHP_BINARY)->stdin($fixture))
            ->toThrow(InvalidArgumentException::class, 'stdin resource must be a stream.');
    } finally {
        proc_terminate($fixture, 9);
        proc_close($fixture);
    }
});

it('requires a consumer for stream mode', function (): void {
    $command = Command::executable(PHP_BINARY)->output(IoMode::STREAM, IoMode::NULL);
    expect(fn () => processRunner()->run($command))->toThrow(ProcessStartException::class);
});
