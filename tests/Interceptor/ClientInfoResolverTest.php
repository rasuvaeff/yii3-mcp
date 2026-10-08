<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Implementation;
use Mcp\Server\Stateless\RequestMeta;
use Rasuvaeff\Yii3Mcp\Interceptor\ClientInfoResolver;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ClientInfoResolver::class)]
final class ClientInfoResolverTest
{
    public function handshakeEraReadsTheInitializeClientInfo(): void
    {
        $info = ClientInfoResolver::fromSession(new FakeSession([
            'client_info' => ['name' => 'claude', 'version' => '2.0', 'title' => 'Claude'],
        ]));

        Assert::same($info?->name, 'claude');
        Assert::same($info?->version, '2.0');
        Assert::same($info?->title, 'Claude');
    }

    /**
     * The modern era has no initialize: clientInfo arrives in each request's
     * `_meta` and the SDK puts it on the per-request session as RequestMeta.
     */
    public function modernEraReadsTheRequestMeta(): void
    {
        $meta = new RequestMeta(
            protocolVersion: '2026-07-28',
            clientCapabilities: new ClientCapabilities(),
            clientInfo: new Implementation(name: 'agent', version: '1.0'),
        );

        $info = ClientInfoResolver::fromSession(new FakeSession([
            RequestMeta::class => $meta,
            'client_info' => ['name' => 'stale', 'version' => '0.1'],
        ]));

        Assert::same($info?->name, 'agent');
    }

    public function modernEraWithoutClientInfoIsNull(): void
    {
        $meta = new RequestMeta(protocolVersion: '2026-07-28', clientCapabilities: new ClientCapabilities());

        Assert::null(ClientInfoResolver::fromSession(new FakeSession([RequestMeta::class => $meta])));
    }

    /**
     * The spec requires a version, but real clients omit it: the name must
     * still come through, with an empty version.
     */
    public function aClientWithoutVersionKeepsItsName(): void
    {
        $info = ClientInfoResolver::fromSession(new FakeSession(['client_info' => ['name' => 'no-version']]));

        Assert::same($info?->name, 'no-version');
        Assert::same($info?->version, '');
    }

    public function malformedHandshakeClientInfoIsNull(): void
    {
        Assert::null(ClientInfoResolver::fromSession(new FakeSession(['client_info' => ['version' => '1.0']])));
        Assert::null(ClientInfoResolver::fromSession(new FakeSession(['client_info' => ['name' => 42, 'version' => '1.0']])));
        Assert::null(ClientInfoResolver::fromSession(new FakeSession(['client_info' => 'corrupted'])));
    }

    public function noSessionIsNull(): void
    {
        Assert::null(ClientInfoResolver::fromSession(null));
        Assert::null(ClientInfoResolver::fromSession(new FakeSession([])));
    }
}
