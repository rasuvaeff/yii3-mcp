<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use InvalidArgumentException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Stands in for the PSR-17 stack failing on deployment detail rather than on
 * the caller's arguments — an unparseable request URI, for instance. It
 * throws a BARE InvalidArgumentException on purpose: that is the type the
 * bridged handler must NOT hand to the MCP client.
 */
final readonly class ThrowingRequestFactory implements RequestFactoryInterface
{
    public const string MESSAGE = 'Unable to parse URI: "https://internal.svc.cluster.local/x"';

    #[\Override]
    public function createRequest(string $method, $uri): RequestInterface
    {
        throw new InvalidArgumentException(self::MESSAGE);
    }
}
