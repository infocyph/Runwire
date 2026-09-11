<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Internal;

final class PhpWarningCapture
{
    /**
     * Execute one PHP operation while converting an E_WARNING into explicit caller-visible state.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function run(callable $operation, ?string &$warning = null): mixed
    {
        $warning = null;

        set_error_handler(
            static function (int $severity, string $message) use (&$warning): bool {
                if (($severity & E_WARNING) === 0) {
                    return false;
                }

                $warning = $message;

                return true;
            },
            E_WARNING,
        );

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
