<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Doctor;

use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\Yii3Mcp\Doctor\CheckStatus;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\LyingSessionStore;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Rasuvaeff\Yii3Mcp\Tests\Support\ThrowingSessionStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpDoctor::class)]
final class McpDoctorTest
{
    private string $sessionDir;

    #[BeforeTest]
    public function prepareSessionDir(): void
    {
        $this->sessionDir = sys_get_temp_dir() . '/yii3-mcp-doctor-' . bin2hex(random_bytes(8));
    }

    #[AfterTest]
    public function removeSessionDir(): void
    {
        if (is_dir($this->sessionDir)) {
            array_map(unlink(...), glob($this->sessionDir . '/*') ?: []);
            rmdir($this->sessionDir);
        }
    }

    public function healthyConfigurationPassesEveryCheck(): void
    {
        $report = $this->doctor()->diagnose();

        Assert::true($report->healthy());
        Assert::same($report->exitCode(), 0);
        Assert::same(
            array_column($report->toArray()['checks'], 'name'),
            ['endpoint_secret', 'session_directory', 'session_store', 'openapi_spec', 'server_build'],
        );
        // Disabled OpenAPI bridge is a skip, not a pass.
        Assert::same($report->checks[3]->status, CheckStatus::Skip);
    }

    public function emptySecretFailsWithConfigExitCode(): void
    {
        $report = $this->doctor(secret: '')->diagnose();

        Assert::false($report->healthy());
        Assert::same($report->exitCode(), 2);
        Assert::same($report->checks[0]->status, CheckStatus::Fail);
    }

    public function clientSecretsSatisfyTheSecretCheck(): void
    {
        $report = $this->doctor(secret: '', clientIds: ['ci', 'claude'])->diagnose();

        Assert::same($report->checks[0]->status, CheckStatus::Pass);
        Assert::string($report->checks[0]->details)->contains('2 client(s)');
        Assert::string($report->checks[0]->details)->contains('claude');
    }

    public function bothSecretFormsTogetherFailTheSecretCheck(): void
    {
        $report = $this->doctor(secret: 'single', clientIds: ['ci'])->diagnose();

        Assert::same($report->checks[0]->status, CheckStatus::Fail);
        Assert::same($report->exitCode(), 2);
        Assert::string($report->checks[0]->details)->contains('exactly one');
    }

    public function uncreatableSessionDirectoryFailsWithStorageExitCode(): void
    {
        // A regular file at the path: mkdir cannot create a directory there.
        $file = sys_get_temp_dir() . '/yii3-mcp-doctor-file-' . bin2hex(random_bytes(8));
        touch($file);

        try {
            $report = $this->doctor(sessionDir: $file)->diagnose();
        } finally {
            unlink($file);
        }

        Assert::false($report->healthy());
        Assert::same($report->exitCode(), 3);
        Assert::string($report->checks[1]->details)->contains($file);
    }

    public function throwingSessionStoreFailsWithStorageExitCode(): void
    {
        $report = $this->doctor(store: new ThrowingSessionStore())->diagnose();

        Assert::false($report->healthy());
        Assert::same($report->exitCode(), 3);
        Assert::string($report->checks[2]->details)->contains('disk on fire');
    }

    public function missingSpecFileFailsWithConfigExitCode(): void
    {
        $report = $this->doctor(specPath: '/definitely/missing/openapi.json')->diagnose();

        Assert::false($report->healthy());
        Assert::same($report->exitCode(), 2);
        Assert::same($report->checks[3]->status, CheckStatus::Fail);
    }

    public function urlSpecIsSkippedWithoutProbeAndTheReportStaysHealthy(): void
    {
        $report = $this->doctor(specPath: 'https://api.example.test/openapi.json')->diagnose();

        Assert::true($report->healthy());
        // Both the spec fetch and the server build (which loads the spec
        // eagerly) stay off the network without --probe.
        Assert::same($report->checks[3]->status, CheckStatus::Skip);
        Assert::same($report->checks[4]->status, CheckStatus::Skip);
    }

    public function probeFetchesTheUrlSpecAndPasses(): void
    {
        $client = new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        $report = $this->doctor(specPath: 'https://api.example.test/openapi.json', httpClient: $client)->diagnose(probeUpstream: true);

        Assert::same($report->checks[3]->status, CheckStatus::Pass);
    }

    public function probeFailureIsReportedWithUpstreamExitCode(): void
    {
        $client = new FakeHttpClient(statusCode: 500, body: 'boom');

        $report = $this->doctor(specPath: 'https://api.example.test/openapi.json', httpClient: $client)->diagnose(probeUpstream: true);

        Assert::false($report->healthy());
        Assert::same($report->exitCode(), 4);
    }

    public function unresolvableServerFailsTheBuildCheck(): void
    {
        $report = $this->doctor(withServer: false)->diagnose();

        Assert::false($report->healthy());
        Assert::same($report->checks[4]->status, CheckStatus::Fail);
        Assert::same($report->exitCode(), 2);
    }

    public function createsAMissingSessionDirectoryWithGroupWritablePermissions(): void
    {
        $previousUmask = umask(0);

        try {
            $report = $this->doctor()->diagnose();
        } finally {
            umask($previousUmask);
        }

        Assert::same($report->checks[1]->status, CheckStatus::Pass);
        Assert::same(substr(sprintf('%o', (int) fileperms($this->sessionDir)), -3), '775');
    }

    public function sessionProbeLeavesNoSessionBehind(): void
    {
        $store = new FileSessionStore(directory: $this->sessionDir, ttl: 3600);

        $report = $this->doctor(store: $store)->diagnose();

        Assert::same($report->checks[2]->status, CheckStatus::Pass);
        Assert::same(glob($this->sessionDir . '/*'), []);
    }

    public function storeThatDoesNotReadBackTheProbeFails(): void
    {
        $report = $this->doctor(store: new LyingSessionStore())->diagnose();

        Assert::false($report->healthy());
        Assert::same($report->checks[2]->status, CheckStatus::Fail);
        Assert::string($report->checks[2]->details)->contains('did not read back');
    }

    public function probeWithAMisboundRequestFactoryReportsTheBindingProblem(): void
    {
        $doctor = new McpDoctor(
            container: new SimpleContainer([
                ClientInterface::class => new FakeHttpClient(),
                RequestFactoryInterface::class => new \stdClass(),
            ]),
            sessionStore: new InMemorySessionStore(),
            endpointSecret: 'test-secret',
            sessionDirectory: $this->sessionDir,
            openApiSpecPath: 'https://api.example.test/openapi.json',
        );

        $report = $doctor->diagnose(probeUpstream: true);

        Assert::same($report->checks[3]->status, CheckStatus::Fail);
        Assert::string($report->checks[3]->details)->contains('must be bound in the container');
    }

    public function reportNeverContainsTheSecret(): void
    {
        $report = $this->doctor(secret: 'super-secret-value')->diagnose();

        $json = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

        Assert::false(str_contains($json, 'super-secret-value'));
    }

    public function reportNeverContainsConfiguredHeaderValues(): void
    {
        $client = new FakeHttpClient(statusCode: 500, body: 'boom');

        $report = $this->doctor(
            specPath: 'https://api.example.test/openapi.json',
            httpClient: $client,
            headers: ['Authorization' => 'Bearer token-value'],
        )->diagnose(probeUpstream: true);

        $json = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

        Assert::false(str_contains($json, 'token-value'));
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $clientIds
     */
    private function doctor(
        string $secret = 'test-secret',
        ?string $sessionDir = null,
        ?SessionStoreInterface $store = null,
        string $specPath = '',
        ?ClientInterface $httpClient = null,
        bool $withServer = true,
        array $headers = [],
        array $clientIds = [],
    ): McpDoctor {
        $factory = new Psr17Factory();
        $definitions = [
            ClientInterface::class => $httpClient ?? new FakeHttpClient(),
            RequestFactoryInterface::class => $factory,
        ];

        if ($withServer) {
            $definitions[Server::class] = (new McpServerFactory(
                container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
                sessionStore: new InMemorySessionStore(),
            ))->create([GreetingTool::class]);
        }

        return new McpDoctor(
            container: new SimpleContainer($definitions),
            sessionStore: $store ?? new InMemorySessionStore(),
            endpointSecret: $secret,
            sessionDirectory: $sessionDir ?? $this->sessionDir,
            openApiSpecPath: $specPath,
            openApiHeaders: $headers,
            clientSecretIds: $clientIds,
        );
    }
}
