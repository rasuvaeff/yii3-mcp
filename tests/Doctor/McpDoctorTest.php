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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Mcp\Doctor\CheckResult;
use Rasuvaeff\Yii3Mcp\Doctor\CheckStatus;
use Rasuvaeff\Yii3Mcp\Doctor\DoctorReport;
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
        Assert::true(in_array('service_http_message_serverrequestfactoryinterface', array_column($report->toArray()['checks'], 'name'), true));
        // Disabled OpenAPI bridge is a skip, not a pass.
        Assert::same($this->check($report, 'openapi_spec')->status, CheckStatus::Skip);
    }

    public function emptySecretFailsWithConfigExitCode(): void
    {
        $report = $this->doctor(secret: '')->diagnose();

        Assert::false($report->healthy());
        Assert::same($report->exitCode(), 2);
        Assert::same($this->check($report, 'endpoint_secret')->status, CheckStatus::Fail);
    }

    public function clientSecretsSatisfyTheSecretCheck(): void
    {
        $report = $this->doctor(secret: '', clientIds: ['ci', 'claude'])->diagnose();

        Assert::same($this->check($report, 'endpoint_secret')->status, CheckStatus::Pass);
        Assert::string($this->check($report, 'endpoint_secret')->details)->contains('2 client(s)');
        Assert::string($this->check($report, 'endpoint_secret')->details)->contains('claude');
    }

    public function bothSecretFormsTogetherFailTheSecretCheck(): void
    {
        $report = $this->doctor(secret: 'single', clientIds: ['ci'])->diagnose();

        Assert::same($this->check($report, 'endpoint_secret')->status, CheckStatus::Fail);
        Assert::same($report->exitCode(), 2);
        Assert::string($this->check($report, 'endpoint_secret')->details)->contains('exactly one');
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
        Assert::string($this->check($report, 'session_directory')->details)->contains($file);
    }

    public function throwingSessionStoreFailsWithStorageExitCode(): void
    {
        $report = $this->doctor(store: new ThrowingSessionStore())->diagnose();

        Assert::false($report->healthy());
        Assert::same($report->exitCode(), 3);
        Assert::string($this->check($report, 'session_store')->details)->contains('disk on fire');
    }

    public function missingSpecFileFailsWithConfigExitCode(): void
    {
        $report = $this->doctor(specPath: '/definitely/missing/openapi.json')->diagnose();

        Assert::false($report->healthy());
        Assert::same($report->exitCode(), 2);
        Assert::same($this->check($report, 'openapi_spec')->status, CheckStatus::Fail);
    }

    public function urlSpecIsSkippedWithoutProbeAndTheReportStaysHealthy(): void
    {
        $report = $this->doctor(specPath: 'https://api.example.test/openapi.json')->diagnose();

        Assert::true($report->healthy());
        // Both the spec fetch and the server build (which loads the spec
        // eagerly) stay off the network without --probe.
        Assert::same($this->check($report, 'openapi_spec')->status, CheckStatus::Skip);
        Assert::same($this->check($report, 'server_build')->status, CheckStatus::Skip);
    }

    public function probeFetchesTheUrlSpecAndPasses(): void
    {
        $client = new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        $report = $this->doctor(specPath: 'https://api.example.test/openapi.json', httpClient: $client)->diagnose(probeUpstream: true);

        Assert::same($this->check($report, 'openapi_spec')->status, CheckStatus::Pass);
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
        Assert::same($this->check($report, 'server_build')->status, CheckStatus::Fail);
        Assert::same($report->exitCode(), 2);
    }

    public function createsAMissingSessionDirectoryOwnerOnly(): void
    {
        // even with the most permissive umask the created directory must be
        // owner-only — session JSON is confidential
        $previousUmask = umask(0);

        try {
            $report = $this->doctor()->diagnose();
        } finally {
            umask($previousUmask);
        }

        Assert::same($this->check($report, 'session_directory')->status, CheckStatus::Pass);
        Assert::same(substr(sprintf('%o', (int) fileperms($this->sessionDir)), -3), '700');
    }

    public function groupReadableSessionDirectoryFailsTheConfidentialityCheck(): void
    {
        mkdir($this->sessionDir, 0o750, true);
        chmod($this->sessionDir, 0o750);

        $report = $this->doctor()->diagnose();

        $check = $this->check($report, 'session_directory');
        Assert::same($check->status, CheckStatus::Fail);
        Assert::string($check->details)
            ->contains('accessible to other OS users')
            ->contains('mode 750');
    }

    public function othersExecuteBitAloneFailsTheConfidentialityCheck(): void
    {
        // 0o701: the LOWEST access bit others can hold — the check must
        // cover the full group+others mask, not just the readable bits
        mkdir($this->sessionDir, 0o701, true);
        chmod($this->sessionDir, 0o701);

        $report = $this->doctor()->diagnose();

        $check = $this->check($report, 'session_directory');
        Assert::same($check->status, CheckStatus::Fail);
        Assert::string($check->details)->contains('mode 701');
    }

    public function credentialBearingSpecUrlIsRedactedInTheReport(): void
    {
        $report = $this->doctor(specPath: 'https://svc:hunter2@spec.example.test/openapi.json')
            ->diagnose(probeUpstream: true);

        $check = $this->check($report, 'openapi_spec');
        Assert::same($check->status, CheckStatus::Fail);
        Assert::string($check->details)->contains('https://***@spec.example.test/openapi.json');
        Assert::false(str_contains($check->details, 'hunter2'));
    }

    public function exceptionDetailsAreRedactedAndTruncated(): void
    {
        $store = new ThrowingSessionStore('token leak https://svc:hunter2@internal.test/x ' . str_repeat('A', 600));

        $report = $this->doctor(store: $store)->diagnose();

        $details = $this->check($report, 'session_store')->details;
        Assert::string($details)
            ->contains('https://***@internal.test/x')
            ->contains('…');
        Assert::false(str_contains($details, 'hunter2'));
    }

    public function exceptionMessageAtTheTruncationBoundaryIsKeptWhole(): void
    {
        $report = $this->doctor(store: new ThrowingSessionStore(str_repeat('B', 500)))->diagnose();

        $details = $this->check($report, 'session_store')->details;
        Assert::string($details)->contains(str_repeat('B', 500));
        Assert::false(str_contains($details, '…'));
    }

    public function nonUtf8ExceptionMessageBecomesAPlaceholder(): void
    {
        $report = $this->doctor(store: new ThrowingSessionStore("\xFF\xFE"))->diagnose();

        Assert::string($this->check($report, 'session_store')->details)
            ->contains('<non-UTF-8 exception message, 2 bytes>');
    }

    public function sessionProbeLeavesNoSessionBehind(): void
    {
        $store = new FileSessionStore(directory: $this->sessionDir, ttl: 3600);

        $report = $this->doctor(store: $store)->diagnose();

        Assert::same($this->check($report, 'session_store')->status, CheckStatus::Pass);
        Assert::same(glob($this->sessionDir . '/*'), []);
    }

    public function storeThatDoesNotReadBackTheProbeFails(): void
    {
        $report = $this->doctor(store: new LyingSessionStore())->diagnose();

        Assert::false($report->healthy());
        Assert::same($this->check($report, 'session_store')->status, CheckStatus::Fail);
        Assert::string($this->check($report, 'session_store')->details)->contains('did not read back');
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

        Assert::same($this->check($report, 'openapi_spec')->status, CheckStatus::Fail);
        Assert::string($this->check($report, 'openapi_spec')->details)->contains('must be bound in the container');
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

    public function reportsTheExactMissingConsoleFactory(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
        ))->create([GreetingTool::class]);
        $doctor = new McpDoctor(
            container: new SimpleContainer([
                Server::class => $server,
                ResponseFactoryInterface::class => $factory,
                StreamFactoryInterface::class => $factory,
            ]),
            sessionStore: new InMemorySessionStore(),
            endpointSecret: 'test-secret',
            sessionDirectory: $this->sessionDir,
            openApiSpecPath: '',
        );

        $check = $this->check($doctor->diagnose(), 'service_http_message_serverrequestfactoryinterface');

        Assert::same($check->status, CheckStatus::Fail);
        Assert::string($check->details)->contains(ServerRequestFactoryInterface::class);
        Assert::false(str_contains($check->details, RequestFactoryInterface::class . ';'));
    }

    public function toolResultCacheEnabledWithoutABoundCacheFails(): void
    {
        $factory = new Psr17Factory();
        $doctor = new McpDoctor(
            container: new SimpleContainer([
                ClientInterface::class => new FakeHttpClient(),
                RequestFactoryInterface::class => $factory,
                ServerRequestFactoryInterface::class => $factory,
                ResponseFactoryInterface::class => $factory,
                StreamFactoryInterface::class => $factory,
            ]),
            sessionStore: new InMemorySessionStore(),
            endpointSecret: 'test-secret',
            sessionDirectory: $this->sessionDir,
            openApiSpecPath: '',
            toolResultCacheEnabled: true,
        );

        $check = $this->check($doctor->diagnose(), 'service_simplecache_cacheinterface');

        Assert::same($check->status, CheckStatus::Fail);
        Assert::string($check->details)->contains('Tool result cache');
    }

    public function toolResultCacheDisabledSkipsTheCheck(): void
    {
        $report = $this->doctor()->diagnose();

        Assert::true($report->healthy());
    }

    public function expectedProductionHostMustBeAllowListed(): void
    {
        $report = $this->doctor(expectedHttpHost: 'mcp.example.test')->diagnose();

        Assert::same($this->check($report, 'allowed_host')->status, CheckStatus::Fail);
        Assert::string($this->check($report, 'allowed_host')->details)->contains('mcp.example.test');
    }

    public function expectedProductionHostPassesWhenAllowListed(): void
    {
        $report = $this->doctor(
            expectedHttpHost: 'mcp.example.test',
            allowedHosts: ['mcp.example.test'],
        )->diagnose();

        Assert::same($this->check($report, 'allowed_host')->status, CheckStatus::Pass);
    }

    public function expectedHostMatchingIsCaseInsensitive(): void
    {
        $report = $this->doctor(
            expectedHttpHost: 'MCP.EXAMPLE.TEST',
            allowedHosts: ['unused.example.test', 'mcp.example.test'],
        )->diagnose();

        Assert::same($this->check($report, 'allowed_host')->status, CheckStatus::Pass);
    }

    public function allowedHostMatchingIsCaseInsensitiveAcrossTheFullList(): void
    {
        $report = $this->doctor(
            expectedHttpHost: 'mcp.example.test',
            allowedHosts: ['unused.example.test', 'MCP.EXAMPLE.TEST'],
        )->diagnose();

        Assert::same($this->check($report, 'allowed_host')->status, CheckStatus::Pass);
    }

    public function loopbackHostsAreAlwaysAllowed(): void
    {
        $report = $this->doctor(expectedHttpHost: '127.0.0.1')->diagnose();

        Assert::same($this->check($report, 'allowed_host')->status, CheckStatus::Pass);
    }

    public function expectedHostWithAPortMatchesTheAllowListedHostname(): void
    {
        // the SDK's own runtime check (DnsRebindingProtectionMiddleware)
        // strips the port from the real Host header before comparing against
        // a port-less allowedHosts entry — the Doctor check must do the same,
        // or it false-negatives on a host the runtime actually allows
        $report = $this->doctor(
            expectedHttpHost: 'mcp.example.test:8080',
            allowedHosts: ['mcp.example.test'],
        )->diagnose();

        Assert::same($this->check($report, 'allowed_host')->status, CheckStatus::Pass);
    }

    public function expectedIpv6HostStaysBracketedAndIsNotPortStripped(): void
    {
        $report = $this->doctor(expectedHttpHost: '[::1]')->diagnose();

        Assert::same($this->check($report, 'allowed_host')->status, CheckStatus::Pass);
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
        string $expectedHttpHost = '',
        array $allowedHosts = [],
    ): McpDoctor {
        $factory = new Psr17Factory();
        $definitions = [
            ClientInterface::class => $httpClient ?? new FakeHttpClient(),
            RequestFactoryInterface::class => $factory,
            ServerRequestFactoryInterface::class => $factory,
            ResponseFactoryInterface::class => $factory,
            StreamFactoryInterface::class => $factory,
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
            expectedHttpHost: $expectedHttpHost,
            allowedHosts: $allowedHosts,
        );
    }

    private function check(DoctorReport $report, string $name): CheckResult
    {
        foreach ($report->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        throw new \LogicException(sprintf('Doctor check "%s" was not found', $name));
    }
}
