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
                        // array response: valid JSON, but NOT advertised as
                        // outputSchema (MCP requires type "object")
                        'responses' => [
                            '200' => [
                                'description' => 'Tag list',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/BlogTag']],
                                    ],
                                ],
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
                        'responses' => [
                            '200' => ['$ref' => '#/components/responses/BlogTagResponse'],
                            '404' => ['description' => 'Not found'],
                        ],
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
                        'responses' => [
                            '2XX' => ['description' => 'wildcard, never advertised'],
                            '204' => ['description' => 'No content'],
                            '201' => [
                                'description' => 'Created',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => ['id' => ['type' => 'integer']],
                                            'required' => ['id'],
                                        ],
                                    ],
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
                    'BlogTag' => [
                        'type' => 'object',
                        'properties' => [
                            'slug' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                        ],
                        'required' => ['slug'],
                    ],
                ],
                'responses' => [
                    'BlogTagResponse' => [
                        'description' => 'One blog tag',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/BlogTag'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
