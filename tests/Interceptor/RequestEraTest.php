<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Schema\ClientCapabilities;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\RequestMeta;
use Rasuvaeff\Yii3Mcp\Interceptor\RequestEra;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RequestEra::class)]
final class RequestEraTest
{
    public function aSessionlessCallHasNoMetaAndIsNeitherModernNorARetry(): void
    {
        Assert::null(RequestEra::meta(null));
        Assert::false(RequestEra::isModern(null));
        Assert::false(RequestEra::isRetry(null));
    }

    public function aSessionWithoutTheEraKeysIsAHandshakeFirstRound(): void
    {
        $session = new FakeSession();

        Assert::null(RequestEra::meta($session));
        Assert::false(RequestEra::isModern($session));
        Assert::false(RequestEra::isRetry($session));
    }

    public function metaOnTheSessionMakesTheCallModernAndIsReturnedAsIs(): void
    {
        $meta = new RequestMeta(protocolVersion: '2026-07-28', clientCapabilities: new ClientCapabilities());
        $session = new FakeSession([RequestMeta::class => $meta]);

        Assert::same(RequestEra::meta($session), $meta);
        Assert::true(RequestEra::isModern($session));
        Assert::false(RequestEra::isRetry($session));
    }

    public function aForeignValueUnderTheMetaKeyIsNotMeta(): void
    {
        $session = new FakeSession([RequestMeta::class => 'not meta', InputContext::class => 'not a context']);

        Assert::null(RequestEra::meta($session));
        Assert::false(RequestEra::isModern($session));
        Assert::false(RequestEra::isRetry($session));
    }

    public function inputContextOnTheSessionMakesTheCallARetry(): void
    {
        $session = new FakeSession([InputContext::class => new InputContext()]);

        Assert::true(RequestEra::isRetry($session));
        Assert::false(RequestEra::isModern($session));
    }
}
