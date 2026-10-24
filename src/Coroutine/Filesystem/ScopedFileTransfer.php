<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Filesystem;

use Infocyph\Runwire\Coroutine\Network\AsyncConnection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Send and receive bounded file chunks through the existing scoped executor
 * and async TCP connection. This class does not own either resource.
 */
final readonly class ScopedFileTransfer
{
    public const int DEFAULT_CHUNK_BYTES = 16_384;

    public const int MAX_TRANSFER_BYTES = 67_108_864;

    /** Borrow scope-owned file and network endpoints without changing ownership. */
    public function __construct(private ScopedFiles $files, private AsyncConnection $network) {}

    /**
     * Receive a bounded byte range into the existing file at an explicit offset.
     * This operation never truncates or atomically replaces the target.
     */
    public function receive(
        string $path,
        int $maxBytes,
        int $offset = 0,
        int $chunkBytes = self::DEFAULT_CHUNK_BYTES,
    ): int {
        self::validate($maxBytes, $offset, $chunkBytes);
        $total = 0;

        while ($total < $maxBytes) {
            $bytes = $this->network->receive(min($chunkBytes, $maxBytes - $total));
            if ($bytes === '') {
                break;
            }

            $written = $this->files->write($path, $bytes, $offset + $total);
            if ($written !== strlen($bytes)) {
                throw new RuntimeException('Off-loop file write returned incomplete byte count.');
            }
            $total += $written;
        }

        return $total;
    }

    /**
     * Read file chunks off-loop, respect connection pressure and preserve
     * caller ownership of graceful network closure or reuse.
     */
    public function send(
        string $path,
        int $maxBytes,
        int $offset = 0,
        int $chunkBytes = self::DEFAULT_CHUNK_BYTES,
    ): int {
        self::validate($maxBytes, $offset, $chunkBytes);
        $total = 0;

        while ($total < $maxBytes) {
            $bytes = $this->files->read($path, min($chunkBytes, $maxBytes - $total), $offset + $total);
            if ($bytes === '') {
                break;
            }

            $write = $this->network->write($bytes);
            if (!$write->accepted()) {
                throw new RuntimeException('Network file transfer exceeded the connection send-byte budget.');
            }

            $total += strlen($bytes);
            if ($write->pressured() && $this->network->drain() !== null) {
                throw new RuntimeException('Network peer closed during file transfer drain.');
            }
        }

        return $total;
    }

    private static function validate(int $maxBytes, int $offset, int $chunkBytes): void
    {
        if ($maxBytes < 0 || $maxBytes > self::MAX_TRANSFER_BYTES || $offset < 0
            || $chunkBytes < 1 || $chunkBytes > self::DEFAULT_CHUNK_BYTES) {
            throw new InvalidArgumentException('File transfer size, offset or chunk budget is invalid.');
        }
    }
}
