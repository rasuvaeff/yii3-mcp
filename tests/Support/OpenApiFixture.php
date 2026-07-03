<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

final readonly class OpenApiFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Fixture API', 'version' => '1.0.0'],
            'paths' => [
                '/rest/blog-tags' => [
                    'get' => [
                        'operationId' => 'getBlogTags',
                        'summary' => 'List blog tags',
                        'parameters' => [
                            [
                                'name' => 'locale',
                                'in' => 'query',
                                'required' => false,
                                'description' => 'Locale code',
                                'schema' => ['type' => 'string', 'default' => 'en'],
                            ],
                        ],
                    ],
                ],
                '/rest/blog-tag/{slug}' => [
                    'parameters' => [
                        ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'get' => [
                        'operationId' => 'getBlogTagBySlug',
                        'description' => 'Blog tag by slug',
                    ],
                ],
                '/rest/subscriber' => [
                    'post' => [
                        'operationId' => 'createSubscriber',
                        'summary' => 'Create subscriber',
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SubscriberInput'],
                                ],
                            ],
                        ],
                    ],
                ],
                '/rest/no-id' => [
                    'get' => ['summary' => 'operation without operationId'],
                ],
            ],
            'components' => [
                'schemas' => [
                    'SubscriberInput' => [
                        'type' => 'object',
                        'properties' => ['email' => ['type' => 'string', 'format' => 'email']],
                        'required' => ['email'],
                    ],
                ],
            ],
        ];
    }
}
