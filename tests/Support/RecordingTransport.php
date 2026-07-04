<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Transport\InMemoryTransport;

final class RecordingTransport extends InMemoryTransport
{
    public bool $listened = false;

    #[\Override]
    public function listen(): mixed
    {
        $this->listened = true;

        return parent::listen();
    }
}
