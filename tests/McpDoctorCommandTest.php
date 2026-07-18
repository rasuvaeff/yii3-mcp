<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Rasuvaeff\Yii3Mcp\McpDoctorCommand;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpDoctorCommand::class)]
final class McpDoctorCommandTest
{
    private string $sessionDir;

    #[BeforeTest]
    public function prepareSessionDir(): void
    {
        $this->sessionDir = sys_get_temp_dir() . '/yii3-mcp-doctor-cmd-' . bin2hex(random_bytes(8));
    }

    #[AfterTest]
    public function removeSessionDir(): void
    {
        if (is_dir($this->sessionDir)) {
            array_map(unlink(...), glob($this->sessionDir . '/*') ?: []);
            rmdir($this->sessionDir);
        }
    }

    public function healthyConfigurationExitsZeroWithATable(): void
    {
        $tester = $this->command();

        Assert::same($tester->execute([]), 0);

        $display = $tester->getDisplay();
        Assert::string($display)->contains('Status');
        Assert::string($display)->contains('pass');
        Assert::string($display)->contains('endpoint_secret');
        Assert::string($display)->contains('server_build');
        Assert::string($display)->contains('healthy');
    }

    public function urlSpecStaysLocalWithoutProbe(): void
    {
        $tester = $this->command(specPath: 'https://api.example.test/openapi.json');

        Assert::same($tester->execute([]), 0);

        // Without --probe the spec fetch is skipped, never performed.
        Assert::string($tester->getDisplay())->contains('run with --probe');
    }

    public function probeOptionFetchesTheUrlSpec(): void
    {
        $tester = $this->command(specPath: 'https://api.example.test/openapi.json');

        $tester->execute(['--probe' => true]);

        Assert::string($tester->getDisplay())->contains('Spec fetched and parsed');
    }

    public function emptySecretExitsWithTheConfigCode(): void
    {
        $tester = $this->command(secret: '');

        Assert::same($tester->execute([]), 2);
        Assert::string($tester->getDisplay())->contains('problems (exit code 2)');
    }

    public function jsonOutputIsMachineReadableAndSecretFree(): void
    {
        $tester = $this->command(secret: 'super-secret-value');

        Assert::same($tester->execute(['--json' => true]), 0);

        $display = $tester->getDisplay();
        $decoded = json_decode($display, associative: true, flags: JSON_THROW_ON_ERROR);
        Assert::same($decoded['healthy'], true);
        Assert::same(count($decoded['checks']), 5);
        Assert::false(str_contains($display, 'super-secret-value'));
        // Pretty-printed with unescaped slashes: the session directory path
        // appears verbatim, ready for grep.
        Assert::string($display)->contains("\n    \"");
        Assert::string($display)->contains($this->sessionDir);
    }

    public function jsonOutputCarriesTheFailureExitCode(): void
    {
        $tester = $this->command(secret: '');

        Assert::same($tester->execute(['--json' => true]), 2);

        $decoded = json_decode($tester->getDisplay(), associative: true, flags: JSON_THROW_ON_ERROR);
        Assert::same($decoded['healthy'], false);
        Assert::same($decoded['exitCode'], 2);
    }

    private function command(string $secret = 'test-secret', string $specPath = ''): CommandTester
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
        ))->create([GreetingTool::class]);

        $doctor = new McpDoctor(
            container: new SimpleContainer([
                Server::class => $server,
                ClientInterface::class => new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR)),
                RequestFactoryInterface::class => $factory,
            ]),
            sessionStore: new InMemorySessionStore(),
            endpointSecret: $secret,
            sessionDirectory: $this->sessionDir,
            openApiSpecPath: $specPath,
        );

        return new CommandTester(new McpDoctorCommand($doctor));
    }
}
