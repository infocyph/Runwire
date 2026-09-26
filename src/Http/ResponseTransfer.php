<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Network\WriteResult;
use InvalidArgumentException;
use RuntimeException;

/**
 * Copies an authorized stream into one response using bounded coroutine-aware backpressure.
 */
final readonly class ResponseTransfer
{
    private const int DEFAULT_CHUNK_BYTES = 65_536;

    private const int DEFAULT_CHUNKS_PER_TURN = 8;

    /**
     * Transfer a stream into the response and end it at source EOF.
     *
     * The caller owns response status/headers. This helper owns only body pumping and,
     * by default, closes the source resource when the transfer completes or fails.
     *
     * @param resource $source
     */
    public static function stream(
        CoroutineScope $scope,
        mixed $source,
        ResponseWriterInterface $writer,
        int $chunkBytes = self::DEFAULT_CHUNK_BYTES,
        int $chunksPerTurn = self::DEFAULT_CHUNKS_PER_TURN,
        bool $closeSource = true,
    ): int {
        self::assertPolicy($source, $chunkBytes, $chunksPerTurn);
        $metadata = stream_get_meta_data($source);
        $wasBlocking = self::wasBlocking($metadata);
        if (!stream_set_blocking($source, false)) {
            throw new RuntimeException('Unable to make response transfer source non-blocking.');
        }

        try {
            return self::pump($scope, $source, $writer, $chunkBytes, $chunksPerTurn);
        } finally {
            self::releaseSource($source, $closeSource, $wasBlocking);
        }
    }

    private static function assertAccepted(WriteResult $result): void
    {
        if ($result->accepted()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Response transfer write failed with state "%s".',
            $result->state->value,
        ));
    }

    /** @param resource $source */
    private static function assertPolicy(mixed $source, int $chunkBytes, int $chunksPerTurn): void
    {
        if (!is_resource($source) || get_resource_type($source) !== 'stream') {
            throw new InvalidArgumentException('Response transfer source must be a live stream resource.');
        }
        if ($chunkBytes < 1 || $chunkBytes > 1_048_576) {
            throw new InvalidArgumentException('Response transfer chunk size must be between 1 and 1048576 bytes.');
        }
        if ($chunksPerTurn < 1 || $chunksPerTurn > 1_024) {
            throw new InvalidArgumentException('Response transfer chunks per turn must be between 1 and 1024.');
        }
    }

    private static function awaitDrain(CoroutineScope $scope, ResponseWriterInterface $writer): void
    {
        $deferred = $scope->deferred();
        $writer->onDrain(static function () use ($deferred): void {
            if (!$deferred->future()->isComplete()) {
                $deferred->resolve(null);
            }
        });

        $scope->cancellation()->throwIfCancelled();
        if ($writer->isEnded()) {
            throw new RuntimeException('Response transfer became terminal while waiting for backpressure relief.');
        }

        $deferred->future()->await();
    }

    /** @param resource $source */
    private static function pump(
        CoroutineScope $scope,
        mixed $source,
        ResponseWriterInterface $writer,
        int $chunkBytes,
        int $chunksPerTurn,
    ): int {
        $transferred = 0;
        $chunksThisTurn = 0;

        while (true) {
            $scope->cancellation()->throwIfCancelled();
            $chunk = fread($source, max(1, $chunkBytes));
            if ($chunk === false) {
                throw new RuntimeException('Unable to read response transfer source.');
            }
            if ($chunk === '') {
                if (feof($source)) {
                    self::assertAccepted($writer->end());

                    return $transferred;
                }

                $scope->waitReadable($source);

                continue;
            }

            $transferred += strlen($chunk);
            self::writeChunk($scope, $writer, $chunk, $chunksPerTurn, $chunksThisTurn);
        }
    }

    /** @param resource $source */
    private static function releaseSource(mixed $source, bool $closeSource, bool $wasBlocking): void
    {
        if (!is_resource($source)) {
            return;
        }
        if ($closeSource) {
            fclose($source);

            return;
        }
        if ($wasBlocking) {
            stream_set_blocking($source, true);
        }
    }

    /** @param array<string, mixed> $metadata */
    private static function wasBlocking(array $metadata): bool
    {
        return array_key_exists('blocked', $metadata)
            ? (bool) $metadata['blocked']
            : true;
    }

    private static function writeChunk(
        CoroutineScope $scope,
        ResponseWriterInterface $writer,
        string $chunk,
        int $chunksPerTurn,
        int &$chunksThisTurn,
    ): void {
        $result = $writer->write($chunk);
        self::assertAccepted($result);

        if ($result->pressured()) {
            self::awaitDrain($scope, $writer);
            $chunksThisTurn = 0;

            return;
        }

        ++$chunksThisTurn;
        if ($chunksThisTurn >= $chunksPerTurn) {
            $chunksThisTurn = 0;
            $scope->yieldNow();
        }
    }
}
