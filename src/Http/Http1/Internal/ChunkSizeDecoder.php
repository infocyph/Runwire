<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

final class ChunkSizeDecoder
{
    public function decode(string $line, int $maxBodyBytes, int $bodyReceived): int
    {
        $semicolon = strpos($line, ';');
        $sizeText = $semicolon === false ? $line : substr($line, 0, $semicolon);
        if ($sizeText === '' || preg_match('/^[0-9A-Fa-f]+$/D', $sizeText) !== 1) {
            throw new ParseFailure(400, 'Malformed chunk size.');
        }

        if ($semicolon !== false) {
            $extension = substr($line, $semicolon + 1);
            if (preg_match('/[\x00-\x1F\x7F]/', $extension) === 1) {
                throw new ParseFailure(400, 'Malformed chunk extension.');
            }
        }

        return $this->hexToInt($sizeText, $maxBodyBytes - $bodyReceived);
    }

    private function hexToInt(string $hex, int $remainingBodyBytes): int
    {
        $value = 0;
        $length = strlen($hex);
        for ($index = 0; $index < $length; ++$index) {
            $byte = ord($hex[$index]);
            $nibble = $byte <= 57 ? $byte - 48 : (($byte | 32) - 87);
            if ($value > intdiv(PHP_INT_MAX - $nibble, 16)) {
                throw new ParseFailure(413, 'Chunk size exceeds platform range.');
            }

            $value = ($value * 16) + $nibble;
            if ($value > $remainingBodyBytes) {
                throw new ParseFailure(413, 'Chunked request body exceeds configured limit.');
            }
        }

        return $value;
    }
}
