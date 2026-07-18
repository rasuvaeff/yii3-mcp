<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Prompts\MarkdownPromptsConfigurator;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Testing\SchemaSnapshot;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\ManyCapabilitiesConfigurator;
use Rasuvaeff\Yii3Mcp\Tests\Support\StructuredWeatherTool;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(SchemaSnapshot::class)]
final class SchemaSnapshotTest
{
    private string $path;

    #[BeforeTest]
    public function preparePath(): void
    {
        $this->path = sys_get_temp_dir() . '/yii3-mcp-snapshot-' . bin2hex(random_bytes(8)) . '.json';
    }

    #[AfterTest]
    public function removeSnapshot(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function generatesSnapshotFileOnFirstRun(): void
    {
        SchemaSnapshot::assert($this->tester(), $this->path);

        Assert::true(is_file($this->path));

        $decoded = json_decode((string) file_get_contents($this->path), associative: true);
        Assert::same(array_keys($decoded), ['tools', 'resources', 'resourceTemplates', 'prompts']);
        Assert::same(array_column($decoded['tools'], 'name'), ['explode', 'greet']);

        // definitions are normalized: object keys sorted at every depth
        // (the SDK emits name,inputSchema,description / type,properties,required)
        Assert::same(array_keys($decoded['tools'][1]), ['description', 'inputSchema', 'name']);
        Assert::same(array_keys($decoded['tools'][1]['inputSchema']), ['properties', 'required', 'type']);
    }

    public function writesHumanReviewableJson(): void
    {
        SchemaSnapshot::assert($this->tester(), $this->path);

        $raw = (string) file_get_contents($this->path);

        Assert::true(str_starts_with($raw, '{'));
        Assert::true(str_ends_with($raw, "}\n"));
        Assert::string($raw)->contains("\n    \"");     // pretty-printed
        Assert::string($raw)->contains('app://status'); // slashes not escaped
    }

    public function toleratesObjectEncodedSectionsInTheSnapshotFile(): void
    {
        SchemaSnapshot::assert($this->tester(), $this->path);

        // hand-edited snapshot: a section as a JSON object instead of a list
        $snapshot = json_decode((string) file_get_contents($this->path), associative: true);
        $snapshot['tools'] = array_combine(array_column($snapshot['tools'], 'name'), $snapshot['tools']);
        file_put_contents($this->path, json_encode($snapshot, JSON_THROW_ON_ERROR));

        SchemaSnapshot::assert($this->tester(), $this->path);

        Assert::true(is_file($this->path));
    }

    public function passesWhenServedSchemasMatchTheSnapshot(): void
    {
        SchemaSnapshot::assert($this->tester(), $this->path);
        SchemaSnapshot::assert($this->tester(), $this->path);

        Assert::true(is_file($this->path));
    }

    public function captureIsDeterministic(): void
    {
        Assert::same(SchemaSnapshot::capture($this->tester()), SchemaSnapshot::capture($this->tester()));
    }

    public function captureIncludesEveryPaginatedCapability(): void
    {
        $captured = SchemaSnapshot::capture($this->tester(withManyCapabilities: true));

        Assert::same(count($captured['tools']), 23);
        Assert::same(count($captured['resources']), 22);
        Assert::same(count($captured['resourceTemplates']), 22);
        Assert::same(count($captured['prompts']), 22);
    }

    public function captureIncludesToolOutputSchemas(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([StructuredWeatherTool::class => new StructuredWeatherTool()]),
            sessionStore: new InMemorySessionStore(),
            name: 'snapshot-suite',
            version: '1.0.0',
        ))->create([StructuredWeatherTool::class]);
        $tester = new McpTester(server: $server, requestFactory: $factory, responseFactory: $factory, streamFactory: $factory);

        $captured = SchemaSnapshot::capture($tester);

        // outputSchema is part of the tool definition, so the contract canary
        // guards structured-output drift the same way it guards inputSchema
        Assert::same($captured['tools'][0]['outputSchema']['required'] ?? null, ['city', 'temperature', 'conditions']);
    }

    public function throwsOnAddedCapability(): void
    {
        SchemaSnapshot::assert($this->tester(), $this->path);

        $message = $this->driftMessage($this->tester(withPrompts: true));

        Assert::string($message)->contains('prompts: added [code-review, plain-note]');
        Assert::string($message)->contains($this->path);
        Assert::string($message)->contains('MCP_SNAPSHOT_RECORD=1');
        Assert::false(str_contains($message, 'changed'));
        Assert::false(str_contains($message, 'removed'));
    }

    public function throwsOnRemovedCapability(): void
    {
        SchemaSnapshot::assert($this->tester(withPrompts: true), $this->path);

        $message = $this->driftMessage($this->tester());

        Assert::string($message)->contains('prompts: removed [code-review, plain-note]');
    }

    public function throwsOnChangedDefinition(): void
    {
        SchemaSnapshot::assert($this->tester(), $this->path);

        $snapshot = json_decode((string) file_get_contents($this->path), associative: true);
        $snapshot['tools'][1]['description'] = 'Something else entirely.';
        file_put_contents($this->path, json_encode($snapshot, JSON_THROW_ON_ERROR));

        $message = $this->driftMessage($this->tester());

        Assert::string($message)->contains('tools: changed [greet]');
        Assert::false(str_contains($message, 'explode'));
    }

    public function throwsWhenSnapshotFileIsNotAJsonObject(): void
    {
        file_put_contents($this->path, '42');

        $message = $this->driftMessage($this->tester());

        Assert::string($message)->contains('does not contain a JSON object');
    }

    public function throwsWhenSnapshotPathIsNotWritable(): void
    {
        $caught = null;

        try {
            SchemaSnapshot::assert($this->tester(), sys_get_temp_dir() . '/definitely-missing-' . bin2hex(random_bytes(8)) . '/snapshot.json');
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('Cannot write MCP schema snapshot');
    }

    public function throwsWhenSnapshotFileCannotBeWritten(): void
    {
        // the path is an existing directory: dirname() exists, the write fails
        $dir = sys_get_temp_dir() . '/yii3-mcp-snapshot-dir-' . bin2hex(random_bytes(8));
        mkdir($dir);

        $caught = null;

        try {
            SchemaSnapshot::assert($this->tester(), $dir);
        } catch (RuntimeException $caught) {
        } finally {
            rmdir($dir);
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('Cannot write MCP schema snapshot');
    }

    public function verifyPassesWhenServedSchemasMatchTheSnapshot(): void
    {
        SchemaSnapshot::record($this->tester(), $this->path);

        SchemaSnapshot::verify($this->tester(), $this->path);

        Assert::true(is_file($this->path));
    }

    public function verifyThrowsWhenTheSnapshotIsMissing(): void
    {
        $caught = null;

        try {
            SchemaSnapshot::verify($this->tester(), $this->path);
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('is missing');
        Assert::string($caught->getMessage())->contains('MCP_SNAPSHOT_RECORD=1');
        // verify never creates the file — a lost snapshot must stay an error.
        Assert::false(is_file($this->path));
    }

    public function verifyThrowsOnDrift(): void
    {
        SchemaSnapshot::record($this->tester(), $this->path);

        $caught = null;

        try {
            SchemaSnapshot::verify($this->tester(withPrompts: true), $this->path);
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('prompts: added [code-review, plain-note]');
    }

    public function recordOverwritesAStaleSnapshot(): void
    {
        SchemaSnapshot::record($this->tester(), $this->path);

        SchemaSnapshot::record($this->tester(withPrompts: true), $this->path);

        SchemaSnapshot::verify($this->tester(withPrompts: true), $this->path);
        $decoded = json_decode((string) file_get_contents($this->path), associative: true);
        Assert::same(array_column($decoded['prompts'], 'name'), ['code-review', 'greeting-style', 'plain-note']);
    }

    public function envFlagSwitchesVerifyIntoRecordMode(): void
    {
        putenv('MCP_SNAPSHOT_RECORD=1');

        try {
            // The file is missing; without the flag this would throw.
            SchemaSnapshot::verify($this->tester(), $this->path);
        } finally {
            putenv('MCP_SNAPSHOT_RECORD');
        }

        Assert::true(is_file($this->path));
        SchemaSnapshot::verify($this->tester(), $this->path);
    }

    public function envFlagSwitchesAssertIntoRecordMode(): void
    {
        SchemaSnapshot::assert($this->tester(), $this->path);
        putenv('MCP_SNAPSHOT_RECORD=1');

        try {
            // Served set drifted (prompts added); the flag re-records instead
            // of throwing.
            SchemaSnapshot::assert($this->tester(withPrompts: true), $this->path);
        } finally {
            putenv('MCP_SNAPSHOT_RECORD');
        }

        SchemaSnapshot::verify($this->tester(withPrompts: true), $this->path);
        Assert::true(is_file($this->path));
    }

    public function envFlagZeroDoesNotEnableRecordMode(): void
    {
        putenv('MCP_SNAPSHOT_RECORD=0');

        $caught = null;

        try {
            SchemaSnapshot::verify($this->tester(), $this->path);
        } catch (RuntimeException $caught) {
        } finally {
            putenv('MCP_SNAPSHOT_RECORD');
        }

        Assert::notNull($caught);
        Assert::false(is_file($this->path));
    }

    private function driftMessage(McpTester $tester): string
    {
        $caught = null;

        try {
            SchemaSnapshot::assert($tester, $this->path);
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);

        return $caught->getMessage();
    }

    private function tester(bool $withPrompts = false, bool $withManyCapabilities = false): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($withPrompts, $withManyCapabilities),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    private function server(bool $withPrompts, bool $withManyCapabilities): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'snapshot-suite',
            version: '1.0.0',
        ))->create(
            [GreetingTool::class],
            [
                ...($withPrompts ? [new MarkdownPromptsConfigurator(__DIR__ . '/Support/prompts')] : []),
                ...($withManyCapabilities ? [new ManyCapabilitiesConfigurator()] : []),
            ],
        );
    }
}
