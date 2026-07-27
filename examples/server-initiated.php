<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class DeploymentTool
{
    #[McpTool(
        name: 'release.deploy',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        ),
    )]
    public function deploy(string $version, RequestContext $context): string
    {
        $client = $context->getClientGateway();
        $client->progress(progress: 1, total: 2, message: 'Validation complete');

        if (!$client->supportsElicitation()) {
            throw new RuntimeException('Client does not support required deployment confirmation');
        }

        $confirmation = $client->elicit(
            message: sprintf('Deploy version %s?', $version),
            requestedSchema: new ElicitationSchema(
                properties: [
                    'confirmed' => new BooleanSchemaDefinition(
                        title: 'Confirm deployment',
                        description: 'Allow this deployment to proceed',
                    ),
                ],
                required: ['confirmed'],
            ),
        );

        if (!$confirmation->isAccepted() || ($confirmation->content['confirmed'] ?? false) !== true) {
            throw new RuntimeException('Deployment was not confirmed');
        }

        return sprintf('Deployment %s queued', $version);
    }
}

$factory = new Psr17Factory();
$server = (new McpServerFactory(
    container: new SimpleContainer([DeploymentTool::class => new DeploymentTool()]),
    sessionStore: new InMemorySessionStore(),
    name: 'server-initiated-example',
    version: '1.0.0',
))->create([DeploymentTool::class]);

$tool = (new McpTester($server, $factory, $factory, $factory))->listTools()[0];

// The official annotations pass through unchanged, while the request-scoped
// RequestContext is injected by the SDK and never becomes a client argument.
echo 'annotations: ' . json_encode($tool['annotations'], JSON_THROW_ON_ERROR) . "\n";
echo 'client arguments: ' . implode(', ', array_keys($tool['inputSchema']['properties'])) . "\n";
