<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Benchmarks;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Testo\Bench;
use Yiisoft\Test\Support\Container\SimpleContainer;

final class McpServerFactoryBench
{
    #[Bench(
        callables: [
            'without tools' => [self::class, 'createEmptyServer'],
        ],
        calls: 500,
        iterations: 5,
    )]
    public static function createServerWithTool(): Server
    {
        return self::factory()->create([GreetingTool::class]);
    }

    public static function createEmptyServer(): Server
    {
        return self::factory()->create([]);
    }

    private static function factory(): McpServerFactory
    {
        return new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
        );
    }
}
