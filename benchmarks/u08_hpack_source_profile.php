<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Hpack\HuffmanCodec;
use Infocyph\Runwire\Http\Http2\Hpack\IntegerCodec;
use Infocyph\Runwire\Http\Internal\RequestHeaderValidator;

/**
 * Independent-source HPACK microprofile for published 2.1.2 and candidate.
 *
 * Usage: php benchmarks/u08_hpack_source_profile.php <source-dir> <40-hex-revision> [iterations]
 * Development evidence only; CI does not treat noisy microprofiles as release
 * performance certification. Both source trees use this exact same workload.
 */
$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || count($arguments) < 3 || count($arguments) > 4
    || !is_string($arguments[1] ?? null)
    || !is_string($arguments[2] ?? null)
    || (isset($arguments[3]) && !is_string($arguments[3]))) {
    throw new InvalidArgumentException('Expected source directory, immutable SHA and optional iterations.');
}
$source = realpath($arguments[1]);
$revision = $arguments[2];
$iterations = isset($arguments[3]) ? filter_var($arguments[3], FILTER_VALIDATE_INT) : 320;
if ($source === false || !is_dir($source)
    || preg_match('/^[0-9a-f]{40}$/D', $revision) !== 1
    || !is_int($iterations) || $iterations < 10 || $iterations > 10_000) {
    throw new InvalidArgumentException('HPACK profile source, revision or iteration count is invalid.');
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

$huffman = new HuffmanCodec();
$value = str_repeat('runwire-hpack-measured/', 12);
$encodedValue = $huffman->encode($value);
$headerValidator = new RequestHeaderValidator('HTTP/2');
$headers = [
    [':method', 'GET'],
    [':scheme', 'https'],
    [':authority', 'runwire.test'],
    [':path', '/profile'],
    ['x-profile', 'bounded'],
];
$jobs = [
    'integer_continuation' => static function (int $index): int {
        $value = $index % 2 === 0 ? 16_384 : PHP_INT_MAX;
        $wire = IntegerCodec::encode($value, 5);
        $offset = 0;
        if (IntegerCodec::decode($wire, $offset, 5) !== $value || $offset !== strlen($wire)) {
            throw new RuntimeException('HPACK integer roundtrip failed.');
        }

        return $offset;
    },
    'huffman_bounded_decode' => static function (int $index) use ($huffman, $value, $encodedValue): int {
        if ($huffman->decode($encodedValue, strlen($value)) !== $value) {
            throw new RuntimeException('HPACK Huffman roundtrip failed.');
        }

        return strlen($encodedValue) + ($index & 1);
    },
    'http2_header_validation' => static function (int $index) use ($headerValidator, $headers): int {
        $head = $headerValidator->request($headers);
        if ($head->target !== '/profile') {
            throw new RuntimeException('HTTP/2 request validation mismatch.');
        }

        return strlen($head->target) + ($index & 1);
    },
];

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
        $started = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $checksum += $operation($i + $trial * $iterations);
        }
        $duration = hrtime(true) - $started;
        if (!is_int($duration) || $duration <= 0 || $checksum <= 0) {
            throw new RuntimeException('HPACK microprofile produced invalid results.');
        }
        $samples[] = $duration / $iterations;
    }
    sort($samples, SORT_NUMERIC);
    $average = array_sum($samples) / count($samples);
    $variance = array_sum(array_map(
        static fn(float $ns): float => ($ns - $average) ** 2,
        $samples,
    )) / count($samples);

    return [
        'iterations' => $iterations,
        'trials' => count($samples),
        'median_nanoseconds_per_op' => round($samples[2], 2),
        'cv_percent' => round(100 * sqrt($variance) / $average, 3),
    ];
};

$results = [];
foreach ($jobs as $name => $job) {
    $results[$name] = $measure($job);
}
$encoder = new Encoder(512);
$decoder = new Decoder(512);
$write = static function (int $index) use ($encoder, $decoder): int {
    $headers = [
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'runwire.test'],
        [':path', '/stream/' . ($index % 11)],
        ['x-rotation', 'value-' . ($index % 13)],
        ['authorization', 'private-' . $index],
    ];
    $block = $encoder->encode($headers);
    if ($decoder->decode($block) !== $headers
        || $encoder->dynamicTableBytes() > 512 || $decoder->dynamicTableBytes() > 512) {
        throw new RuntimeException('HPACK encoder/decoder churn violated limits or header fidelity.');
    }
    $frame = FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x4, 1, $block));

    return strlen($frame);
};
$results['header_frame_write_churn'] = $measure($write);
$output = json_encode([
    'schemaVersion' => 1,
    'sourceRevision' => $revision,
    'scope' => 'developer-only-same-workload-microprofile',
    'releaseCertified' => false,
    'workloads' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
if (fwrite(STDOUT, $output . PHP_EOL) === false) {
    throw new RuntimeException('Unable to emit HPACK profile.');
}
