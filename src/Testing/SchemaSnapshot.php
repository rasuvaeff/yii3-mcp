<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Testing;

use RuntimeException;

/**
 * Contract canary for MCP capability schemas: snapshots every served tool,
 * resource, resource template and prompt definition into a committed JSON
 * file and fails when the served set drifts — a changed method signature
 * silently changes the generated inputSchema and breaks agents mid-flight,
 * so drift must be an explicit, reviewed act (regenerate by deleting the
 * snapshot file).
 *
 * ```php
 * SchemaSnapshot::assert($tester, __DIR__ . '/mcp-schema.json');
 * ```
 *
 * @api
 */
final readonly class SchemaSnapshot
{
    private const array SECTIONS = [
        'tools' => ['tools/list', 'tools', 'name'],
        'resources' => ['resources/list', 'resources', 'uri'],
        'resourceTemplates' => ['resources/templates/list', 'resourceTemplates', 'uriTemplate'],
        'prompts' => ['prompts/list', 'prompts', 'name'],
    ];

    /**
     * Compares the served capability schemas against the snapshot file.
     * A missing file is generated (first run passes); a mismatch throws
     * with a per-section summary of the drift.
     *
     * @throws RuntimeException on drift or an unreadable/invalid snapshot file
     */
    public static function assert(McpTester $tester, string $path): void
    {
        $actual = self::capture($tester);

        if (!is_file($path)) {
            self::write($path, $actual);
        } else {
            $expected = self::read($path);

            if ($expected !== $actual) {
                throw new RuntimeException(self::describeDrift($expected, $actual, $path));
            }
        }
    }

    /**
     * Captures the currently served capability definitions, normalized for
     * stable comparison (sections keyed, lists ordered by their identity key,
     * objects with sorted keys).
     *
     * @return array<string, list<array<array-key, mixed>>>
     */
    public static function capture(McpTester $tester): array
    {
        $snapshot = [];

        foreach (self::SECTIONS as $section => [$method, $key, $identity]) {
            $items = [];
            /** @var mixed $item */
            foreach (self::listOf($tester->request($method)[$key] ?? null) as $item) {
                if (is_array($item)) {
                    /** @var array<array-key, mixed> $normalized */
                    $normalized = self::normalize($item);
                    $items[] = $normalized;
                }
            }

            usort($items, static fn(array $a, array $b): int => self::identity($a, $identity) <=> self::identity($b, $identity));
            $snapshot[$section] = $items;
        }

        return $snapshot;
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private static function identity(array $item, string $key): string
    {
        /** @var mixed $value */
        $value = $item[$key] ?? null;

        return is_string($value) ? $value : json_encode($item, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, list<array<array-key, mixed>>> $snapshot
     */
    private static function write(string $path, array $snapshot): void
    {
        if (!is_dir(dirname($path))) {
            throw new RuntimeException(sprintf('Cannot write MCP schema snapshot to "%s"', $path));
        }

        file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * @return array<string, list<array<array-key, mixed>>>
     */
    private static function read(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException(sprintf('Cannot read MCP schema snapshot from "%s"', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('MCP schema snapshot "%s" does not contain a JSON object', $path));
        }

        $snapshot = [];

        foreach (array_keys(self::SECTIONS) as $section) {
            $items = [];
            /** @var mixed $item */
            foreach (self::listOf($decoded[$section] ?? null) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $snapshot[$section] = $items;
        }

        return $snapshot;
    }

    /**
     * @param array<string, list<array<array-key, mixed>>> $expected
     * @param array<string, list<array<array-key, mixed>>> $actual
     */
    private static function describeDrift(array $expected, array $actual, string $path): string
    {
        $drift = [];

        foreach (self::SECTIONS as $section => [2 => $identity]) {
            $expectedByIdentity = self::byIdentity($expected[$section] ?? [], $identity);
            $actualByIdentity = self::byIdentity($actual[$section] ?? [], $identity);

            $added = array_keys(array_diff_key($actualByIdentity, $expectedByIdentity));
            $removed = array_keys(array_diff_key($expectedByIdentity, $actualByIdentity));
            $changed = [];

            foreach (array_intersect_key($actualByIdentity, $expectedByIdentity) as $name => $definition) {
                if ($definition !== $expectedByIdentity[$name]) {
                    $changed[] = $name;
                }
            }

            $parts = [];

            if ($added !== []) {
                $parts[] = 'added [' . implode(', ', $added) . ']';
            }

            if ($removed !== []) {
                $parts[] = 'removed [' . implode(', ', $removed) . ']';
            }

            if ($changed !== []) {
                $parts[] = 'changed [' . implode(', ', $changed) . ']';
            }

            if ($parts !== []) {
                $drift[] = $section . ': ' . implode(', ', $parts);
            }
        }

        return sprintf(
            'MCP schema snapshot mismatch at "%s" — %s. If the change is intended, delete the snapshot file and re-run to regenerate it deliberately',
            $path,
            $drift === [] ? 'definitions differ' : implode('; ', $drift),
        );
    }

    /**
     * @param list<array<array-key, mixed>> $items
     *
     * @return array<string, array<array-key, mixed>>
     */
    private static function byIdentity(array $items, string $key): array
    {
        $indexed = [];

        foreach ($items as $item) {
            $indexed[self::identity($item, $key)] = $item;
        }

        return $indexed;
    }
}
