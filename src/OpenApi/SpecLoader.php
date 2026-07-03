<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;

/**
 * Fetches the OpenAPI document over HTTP — for APIs that serve their spec
 * from an endpoint (always current, no exported file to regenerate). The
 * same default headers as the operation calls apply, so a spec endpoint
 * behind authentication works out of the box.
 *
 * @api
 */
final readonly class SpecLoader
{
    /**
     * @param array<string, string> $headers e.g. ['Authorization' => 'Bearer …']
     */
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private array $headers = [],
    ) {}

    public function fromUrl(string $url): SpecIndex
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json');

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new InvalidSpecException(sprintf(
                'OpenAPI document at "%s" is not available: HTTP %d',
                $url,
                $response->getStatusCode(),
            ));
        }

        return SpecIndex::fromJson((string) $response->getBody());
    }
}
