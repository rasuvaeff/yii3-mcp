<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use RuntimeException;

/**
 * A stream wrapper whose stream_open() always throws — used to simulate a
 * custom stream (e.g. a network filesystem) failing with an EXCEPTION
 * instead of the usual `false` + PHP warning, the scenario PromptFile::parse()'s
 * try/finally guards against.
 */
final class ThrowingStreamWrapper
{
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        throw new RuntimeException('Simulated stream failure for ' . $path);
    }
}
