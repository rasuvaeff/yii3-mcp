<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Benchmarks;

use Rasuvaeff\Mcp\McpServerComponents;
use Rasuvaeff\Yii3Mcp\McpServerComponentResolver;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Testo\Bench;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * Params interpretation: under PHP-FPM it runs on every MCP request.
 */
final class McpServerComponentResolverBench
{
    public static function resolveWithGuards(): McpServerComponents
    {
        return (new McpServerComponentResolver(
            container: new SimpleContainer([RecordingInterceptor::class => new RecordingInterceptor()]),
            params: array_replace(self::params(), [
                'tools' => [GreetingTool::class],
                'interceptors' => [RecordingInterceptor::class],
                'visibility' => ['deny' => ['admin.*'], 'allow' => []],
            ]),
        ))->resolve();
    }

    #[Bench(
        callables: [
            'with guards' => [self::class, 'resolveWithGuards'],
        ],
        calls: 2000,
        iterations: 5,
    )]
    public static function resolveDefaults(): McpServerComponents
    {
        return (new McpServerComponentResolver(container: new SimpleContainer([]), params: self::params()))->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private static function params(): array
    {
        static $params = null;

        return ($params ??= require __DIR__ . '/../config/params.php')['rasuvaeff/yii3-mcp'];
    }
}
