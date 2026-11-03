<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Closure;
use ErrorException;

/**
 * Runs a callable with PHP warnings turned into exceptions. A defensive
 * branch that "works" only because PHP warns and carries on (an undefined
 * variable, a foreach over a non-array) is a branch whose guard has been
 * lost; a warning that is merely printed is invisible to every assertion.
 */
final class WarningsAsErrors
{
    /**
     * @template T
     *
     * @param Closure(): T $run
     *
     * @return T
     */
    public static function around(Closure $run): mixed
    {
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
            E_WARNING | E_USER_WARNING,
        );

        try {
            return $run();
        } finally {
            restore_error_handler();
        }
    }
}
