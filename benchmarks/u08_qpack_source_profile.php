<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;
use Infocyph\Runwire\Http\Http3\Qpack\IntegerCodec;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

/**
 * Identical-source QPACK and QUIC varint microprofile for published 2.1.2 and 3.0.
 *
 * Usage: php benchmarks/u08_qpack_source_profile.php <source-dir> <40-hex-revision> [iterations]
 * Development-only diagnostic: this does not measure native QUIC polling,
 * socket writes, end-to-end successful RPS or release-grade performance.
 */
$args = $_SERVER['argv'] ?? null;
if (!is_array($args) || count($args) < 3 || count($args) > 4
    || !is_string($args[1] ?? null) || !is_string($args[2] ?? null)
    || (isset($args[3]) && !is_string($args[3]))) {
    throw new InvalidArgumentException('Expected source directory, immutable revision and optional iterations.');
}
$source = realpath($args[1]);
$revision = $args[2];
$iterations = isset($args[3]) ? filter_var($args[3], FILTER_VALIDATE_INT) : 320;
if ($source === false || !is_dir($source)
    || preg_match('/^[0-9a-f]{40}$/D', $revision) !== 1
    || !is_int($iterations) || $iterations < 10 || $iterations > 10_000) {
    throw new InvalidArgumentException('QPACK profile source, revision or iteration count is invalid.');
}

spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $source . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * @param callable(int):int $operation
 * @return array{iterations:int,trials:int,median_nanoseconds_per_op:float,cv_percent:float}
 */
$measure = static function (callable $operation) use ($iterations): array {
    $checksum = 0;
    for ($i = 0; $i < 64; ++$i) {
        $checksum += $operation($i);
    }
    $samples = [];
    for ($trial = 0; $trial < 5; ++$trial) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $checksum += $operation($trial * $iterations + $i);
        }
        $elapsed = hrtime(true) - $start;
        if (!is_int($elapsed) || $elapsed <= 0 || $checksum <= 0) {
            throw new RuntimeException('QPACK profile produced an invalid sample.');
        }
        $samples[] = $elapsed / $iterations;
    }
    sort($samples, SORT_NUMERIC);
    $mean = array_sum($samples) / count($samples);
    $variance = array_sum(array_map(
        static fn(float $value): float => ($value - $mean) ** 2,
        $samples,
    )) / count($samples);

    return [
        'iterations' => $iterations,
        'trials' => 5,
        'median_nanoseconds_per_op' => round($samples[2], 2),
        'cv_percent' => round(100 * sqrt($variance) / $mean, 3),
    ];
};

$jobs = [
    'qpack_continuation_integer' => static function (int $i): int {
        $value = $i % 2 === 0 ? 16_384 : PHP_INT_MAX;
        $encoded = IntegerCodec::encode($value, 5);
        $offset = 0;
        if (IntegerCodec::decode($encoded, $offset, 5, ErrorCode::QPACK_DECOMPRESSION_FAILED) !== $value
            || $offset !== strlen($encoded)) {
            throw new RuntimeException('QPACK prefixed integer mismatch.');
        }

        return $offset;
    },
    'quic_varint_widths' => static function (int $i): int {
        $values = [63, 16_384, VarIntCodec::MAX_VALUE];
        $value = $values[$i % count($values)];
        $encoded = VarIntCodec::encode($value);
        $offset = 0;
        if (VarIntCodec::decode($encoded, $offset) !== $value || $offset !== strlen($encoded)) {
            throw new RuntimeException('QUIC variable-length integer mismatch.');
        }

        return $offset;
    },
    'qpack_dynamic_block_unblock' => static function (int $i): int {
        $encoder = new Encoder(220, 1, dynamicTableCapacity: 220);
        $decoder = new Decoder(220, 1);
        $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());
        $fields = [
            [':authority', 'www.example.com'],
            [':path', '/sample/path'],
        ];
        $streamId = 4 * ($i + 1);
        $encoded = $encoder->encode($fields, $streamId);
        if ($encoded->requiredInsertCount < 1 || $decoder->decode($encoded->block, $streamId) !== null) {
            throw new RuntimeException('QPACK section did not block on unreceived inserts.');
        }
        $ready = $decoder->pushEncoderInstructions($encoder->takeEncoderInstructions());
        if (count($ready) !== 1 || $ready[0]->streamId !== $streamId
            || $ready[0]->section->fields !== $fields) {
            throw new RuntimeException('QPACK blocked section did not unblock correctly.');
        }
        $encoder->pushDecoderInstructions($decoder->takeDecoderInstructions());
        if ($encoder->knownReceivedCount() < $encoded->requiredInsertCount) {
            throw new RuntimeException('QPACK acknowledgement did not release the blocked section.');
        }

        return strlen($encoded->block) + $encoded->requiredInsertCount;
    },
];

$results = [];
foreach ($jobs as $name => $job) {
    $results[$name] = $measure($job);
}
$json = json_encode([
    'schemaVersion' => 1,
    'sourceRevision' => $revision,
    'scope' => 'development-only-identical-source-qpack-varint-microprofile',
    'releaseCertified' => false,
    'nativeQuicPollMeasured' => false,
    'workloads' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (fwrite(STDOUT, $json . PHP_EOL) === false) {
    throw new RuntimeException('Unable to write QPACK profile output.');
}
