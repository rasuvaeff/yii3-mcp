<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Doctor;

use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecLoader;
use Symfony\Component\Uid\Uuid;

/**
 * Health/readiness diagnostics for the MCP server configuration: runs a fixed
 * set of checks in diagnosis order (root causes first) and returns an
 * immutable {@see DoctorReport}. Backs the `mcp:doctor` console command.
 *
 * Checks: endpoint secret configured (config), session directory usable and
 * a session round-trip through the configured store (storage), OpenAPI spec
 * loadable (config for a local file, upstream for a URL), server build
 * (config). Network is touched only when explicitly requested
 * ({@see diagnose()} with `$probeUpstream = true`) — with a URL spec the
 * spec fetch AND the server build (which loads the spec eagerly) are skipped
 * otherwise, so the base health check stays local.
 *
 * Details never contain the secret or configured header values.
 *
 * @api
 */
final readonly class McpDoctor
{
    /**
     * @param ContainerInterface $container used to build the real {@see Server} definition lazily
     * @param string $sessionDirectory the effective session directory (defaults already resolved)
     * @param array<string, string> $openApiHeaders used for the spec fetch only; never reported
     * @param list<string> $clientSecretIds ids (NOT secrets) from the `client_secrets` param
     */
    public function __construct(
        private ContainerInterface $container,
        private SessionStoreInterface $sessionStore,
        private string $endpointSecret,
        private string $sessionDirectory,
        private string $openApiSpecPath,
        private array $openApiHeaders = [],
        private array $clientSecretIds = [],
    ) {}

    public function diagnose(bool $probeUpstream = false): DoctorReport
    {
        return new DoctorReport([
            $this->checkEndpointSecret(),
            $this->checkSessionDirectory(),
            $this->checkSessionStore(),
            $this->checkOpenApiSpec($probeUpstream),
            $this->checkServerBuild($probeUpstream),
        ]);
    }

    private function checkEndpointSecret(): CheckResult
    {
        if ($this->endpointSecret !== '' && $this->clientSecretIds !== []) {
            return new CheckResult(
                name: 'endpoint_secret',
                category: CheckCategory::Config,
                status: CheckStatus::Fail,
                details: 'Both "endpoint_secret" and "client_secrets" are set; configure exactly one — SharedSecretMiddleware refuses to instantiate with both',
            );
        }

        if ($this->endpointSecret === '' && $this->clientSecretIds === []) {
            return new CheckResult(
                name: 'endpoint_secret',
                category: CheckCategory::Config,
                status: CheckStatus::Fail,
                details: 'No endpoint secret configured: SharedSecretMiddleware answers 503 to every request. Set the "endpoint_secret" param (env MCP_SECRET), configure "client_secrets", or protect the endpoint with a network ACL instead of the middleware',
            );
        }

        return new CheckResult(
            name: 'endpoint_secret',
            category: CheckCategory::Config,
            status: CheckStatus::Pass,
            details: $this->clientSecretIds === []
                ? 'Configured (single secret)'
                : sprintf('Configured: %d client(s) [%s]', count($this->clientSecretIds), implode(', ', $this->clientSecretIds)),
        );
    }

    private function checkSessionDirectory(): CheckResult
    {
        $directory = $this->sessionDirectory;

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true)) {
            return new CheckResult(
                name: 'session_directory',
                category: CheckCategory::Storage,
                status: CheckStatus::Fail,
                details: sprintf('Cannot create session directory "%s"', $directory),
            );
        }

        if (!is_writable($directory)) {
            return new CheckResult(
                name: 'session_directory',
                category: CheckCategory::Storage,
                status: CheckStatus::Fail,
                details: sprintf('Session directory "%s" is not writable', $directory),
            );
        }

        return new CheckResult(
            name: 'session_directory',
            category: CheckCategory::Storage,
            status: CheckStatus::Pass,
            details: sprintf('Writable: %s', $directory),
        );
    }

    private function checkSessionStore(): CheckResult
    {
        $id = Uuid::v4();

        try {
            $written = $this->sessionStore->write($id, 'yii3-mcp doctor probe');
            $read = $this->sessionStore->read($id);
            $this->sessionStore->destroy($id);
        } catch (\Throwable $failure) {
            return new CheckResult(
                name: 'session_store',
                category: CheckCategory::Storage,
                status: CheckStatus::Fail,
                details: sprintf('Session round-trip threw %s: %s', $failure::class, $failure->getMessage()),
            );
        }

        if (!$written || $read !== 'yii3-mcp doctor probe') {
            return new CheckResult(
                name: 'session_store',
                category: CheckCategory::Storage,
                status: CheckStatus::Fail,
                details: 'Session store did not read back the written probe session',
            );
        }

        return new CheckResult(
            name: 'session_store',
            category: CheckCategory::Storage,
            status: CheckStatus::Pass,
            details: sprintf('Round-trip OK via %s', $this->sessionStore::class),
        );
    }

    private function checkOpenApiSpec(bool $probeUpstream): CheckResult
    {
        $path = $this->openApiSpecPath;

        if ($path === '') {
            return new CheckResult(
                name: 'openapi_spec',
                category: CheckCategory::Config,
                status: CheckStatus::Skip,
                details: 'OpenAPI bridge is disabled (spec_path is empty)',
            );
        }

        if ($this->isUrl($path)) {
            if (!$probeUpstream) {
                return new CheckResult(
                    name: 'openapi_spec',
                    category: CheckCategory::Upstream,
                    status: CheckStatus::Skip,
                    details: 'Spec is a URL; run with --probe to fetch it over the network',
                );
            }

            try {
                $this->specLoader()->fromUrl($path);
            } catch (\Throwable $failure) {
                return new CheckResult(
                    name: 'openapi_spec',
                    category: CheckCategory::Upstream,
                    status: CheckStatus::Fail,
                    details: sprintf('Fetching the spec from "%s" threw %s: %s', $path, $failure::class, $failure->getMessage()),
                );
            }

            return new CheckResult(
                name: 'openapi_spec',
                category: CheckCategory::Upstream,
                status: CheckStatus::Pass,
                details: sprintf('Spec fetched and parsed from %s', $path),
            );
        }

        try {
            SpecIndex::fromFile($path);
        } catch (\Throwable $failure) {
            return new CheckResult(
                name: 'openapi_spec',
                category: CheckCategory::Config,
                status: CheckStatus::Fail,
                details: sprintf('Loading the spec file "%s" threw %s: %s', $path, $failure::class, $failure->getMessage()),
            );
        }

        return new CheckResult(
            name: 'openapi_spec',
            category: CheckCategory::Config,
            status: CheckStatus::Pass,
            details: sprintf('Spec file parsed: %s', $path),
        );
    }

    private function checkServerBuild(bool $probeUpstream): CheckResult
    {
        // With a URL spec the Server definition fetches the spec eagerly, so
        // building it is a network operation — only done under --probe to keep
        // the base health check local.
        if ($this->isUrl($this->openApiSpecPath) && !$probeUpstream) {
            return new CheckResult(
                name: 'server_build',
                category: CheckCategory::Config,
                status: CheckStatus::Skip,
                details: 'OpenAPI spec is a URL and the server build fetches it eagerly; run with --probe',
            );
        }

        try {
            $this->container->get(Server::class);
        } catch (\Throwable $failure) {
            return new CheckResult(
                name: 'server_build',
                category: CheckCategory::Config,
                status: CheckStatus::Fail,
                details: sprintf('Building the MCP server threw %s: %s', $failure::class, $failure->getMessage()),
            );
        }

        return new CheckResult(
            name: 'server_build',
            category: CheckCategory::Config,
            status: CheckStatus::Pass,
            details: 'Server built from the configured tools and configurators',
        );
    }

    private function specLoader(): SpecLoader
    {
        $httpClient = $this->container->get(ClientInterface::class);
        $requestFactory = $this->container->get(RequestFactoryInterface::class);

        if (!$httpClient instanceof ClientInterface || !$requestFactory instanceof RequestFactoryInterface) {
            throw new \RuntimeException('A PSR-18 client and a PSR-17 request factory must be bound in the container to fetch a URL OpenAPI spec');
        }

        return new SpecLoader(
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            headers: $this->openApiHeaders,
        );
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }
}
