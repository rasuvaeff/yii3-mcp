<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OAuth;

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Mcp\OAuth\OAuthComponentFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * Every misconfiguration fails when the guard is built, naming the param —
 * never as a request that is let through.
 */
#[Test]
#[Covers(OAuthComponentFactory::class)]
final class OAuthComponentFactoryTest
{
    private const array VALID = [
        'resource' => 'https://mcp.example.com/mcp',
        'authorization_servers' => ['https://auth.example.com'],
    ];

    #[DataProvider('misconfigurations')]
    public function aMisconfigurationFailsWithTheParamName(array $params, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        $this->factory($params)->authorizationMiddleware();
    }

    public static function misconfigurations(): iterable
    {
        yield 'no resource' => [[], 'OAuth is not configured: set rasuvaeff/yii3-mcp oauth.resource to the canonical URI of the MCP endpoint (e.g. https://mcp.example.com/mcp)'];
        yield 'no authorization server' => [['resource' => 'https://mcp.example.com/mcp'], 'rasuvaeff/yii3-mcp oauth.authorization_servers must list at least one issuer that issues tokens for this server'];
        yield 'resource not a string' => [['resource' => 42], 'rasuvaeff/yii3-mcp oauth.resource must be a string'];
        yield 'servers not a list' => [[...self::VALID, 'authorization_servers' => ['a' => 'https://auth.example.com']], 'rasuvaeff/yii3-mcp oauth.authorization_servers must be a list of strings'];
        yield 'empty server' => [[...self::VALID, 'authorization_servers' => ['']], 'rasuvaeff/yii3-mcp oauth.authorization_servers must be a list of non-empty strings'];
        yield 'negative leeway' => [[...self::VALID, 'leeway' => -1], 'rasuvaeff/yii3-mcp oauth.leeway must be a non-negative integer'];
        yield 'scopes not an array' => [[...self::VALID, 'scopes' => 'mcp'], 'rasuvaeff/yii3-mcp oauth.scopes must be an array with default, methods, tools and implies'];
        yield 'unknown scopes key' => [[...self::VALID, 'scopes' => ['tool' => []]], 'Unknown rasuvaeff/yii3-mcp oauth.scopes key(s): tool; supported: default, methods, tools, implies'];
        yield 'tool scopes not a map' => [[...self::VALID, 'scopes' => ['tools' => 'greet']], 'rasuvaeff/yii3-mcp oauth.scopes.tools must map a name to a list of scopes'];
        yield 'tool scopes keyed by position' => [[...self::VALID, 'scopes' => ['tools' => [['mcp:write']]]], 'rasuvaeff/yii3-mcp oauth.scopes.tools must map a name to a list of scopes'];
        yield 'tool scope not a list' => [[...self::VALID, 'scopes' => ['tools' => ['greet' => 'mcp:write']]], 'rasuvaeff/yii3-mcp oauth.scopes.tools.greet must be a list of strings'];
        yield 'validator of the wrong type' => [[...self::VALID, 'validator' => 'not-a-validator'], 'rasuvaeff/yii3-mcp oauth.validator "not-a-validator" must resolve to Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface'];
        yield 'cache of the wrong type' => [[...self::VALID, 'cache' => 'not-a-validator'], 'rasuvaeff/yii3-mcp oauth.cache must resolve to Psr\Cache\CacheItemPoolInterface (it caches the issuer metadata and the signing keys)'];
    }

    public function noRequiredScopeMeansNoPolicy(): void
    {
        Assert::null($this->factory([...self::VALID, 'scopes' => ['implies' => ['a' => ['b']]]])->scopePolicy());
        Assert::notNull($this->factory([...self::VALID, 'scopes' => ['methods' => ['tools/call' => ['mcp']]]])->scopePolicy());
    }

    public function aConfiguredValidatorReplacesTheJwtOne(): void
    {
        $validator = new class implements AuthorizationTokenValidatorInterface {
            #[\Override]
            public function validate(string $accessToken): AuthorizationResult
            {
                return AuthorizationResult::allow(new AccessToken(['mcp'], ['sub' => $accessToken]));
            }
        };

        Assert::same($this->factory([...self::VALID, 'validator' => 'my-validator'], ['my-validator' => $validator])->validator(), $validator);
    }

    public function theMetadataCarriesTheOptionalNameAndDocumentation(): void
    {
        $metadata = $this->factory([...self::VALID, 'resource_name' => 'Fans', 'resource_documentation' => 'https://example.com/docs'])->metadata();

        Assert::same($metadata->jsonSerialize()['resource_name'] ?? null, 'Fans');
        Assert::same($metadata->jsonSerialize()['resource_documentation'] ?? null, 'https://example.com/docs');
        Assert::false(array_key_exists('scopes_supported', $metadata->jsonSerialize()));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $services
     */
    private function factory(array $params, array $services = []): OAuthComponentFactory
    {
        $psr17 = new Psr17Factory();

        return new OAuthComponentFactory(
            params: $params,
            container: new SimpleContainer([
                ResponseFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
                'not-a-validator' => new \stdClass(),
                ...$services,
            ]),
        );
    }
}
