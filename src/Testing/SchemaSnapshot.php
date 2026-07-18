<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Testing;

use RuntimeException;

/**
 * Contract canary for MCP capability schemas: snapshots every served tool,
 * resource, resource template and prompt definition into a committed JSON
 * file and fails when the served set drifts — a changed method signature
 * silently changes the generated inputSchema and breaks agents mid-flight,
 * so drift must be an explicit, reviewed act.
 *
 * Three modes:
 * - {@see verify()} — for CI: a missing snapshot is an error, so a deleted
 *   or never-committed file cannot yield a green build.
 * - {@see assert()} — migration-friendly: a missing snapshot is generated on
 *   first run, then compared like verify().
 * - {@see record()} — deliberately (re)writes the snapshot.
 *
 * Setting the `MCP_SNAPSHOT_RECORD` environment variable to any value except
 * `''`/`'0'` switches assert()/verify() into record mode — the explicit
 * regeneration path (`MCP_SNAPSHOT_RECORD=1 vendor/bin/testo`); CI must not
 * set it.
 *
 * ```php
 * SchemaSnapshot::verify($tester, __DIR__ . '/mcp-schema.json');
 * ```
 *
 * @api
 */
final readonly class SchemaSnapshot
{
    private const string RECORD_ENV = 'MCP_SNAPSHOT_RECORD';

    private const array SECTIONS = [
        'tools' => ['listTools', 'name'],
        'resources' => ['listResources', 'uri'],
        'resourceTemplates' => ['listResourceTemplates', 'uriTemplate'],
        'prompts' => ['listPrompts', 'name'],
    ];

    /**
     * Compares the served capability schemas against the snapshot file.
     * A missing file is generated (first run passes); a mismatch throws
     * with a per-section summary of the drift. Prefer {@see verify()} in CI,
     * where a missing snapshot must be an error.
     *
     * @throws RuntimeException on drift or an unreadable/invalid snapshot file
     */
    public static function assert(McpTester $tester, string $path): void
    {
        if (self::recordRequested() || !is_file($path)) {
            self::write($path, self::capture($tester));
        } else {
            self::compare(self::capture($tester), $path);
        }
    }

    /**
     * Strict form of {@see assert()} for CI: a missing snapshot file is an
     * error instead of being silently generated, so a lost or never-committed
     * snapshot cannot produce a green build.
     *
     * @throws RuntimeException on a missing snapshot, drift, or an unreadable/invalid snapshot file
     */
    public static function verify(McpTester $tester, string $path): void
    {
        if (self::recordRequested()) {
            self::write($path, self::capture($tester));
        } elseif (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'MCP schema snapshot "%s" is missing. Record it deliberately — SchemaSnapshot::record() or a run with MCP_SNAPSHOT_RECORD=1 — and commit the file; verify() treats a missing snapshot as an error so CI cannot pass without the contract canary',
                $path,
            ));
        } else {
            self::compare(self::capture($tester), $path);
        }
    }

    /**
     * Deliberately (re)writes the snapshot file from the currently served
     * capability schemas.
     *
     * @throws RuntimeException when the snapshot file cannot be written
     */
    public static function record(McpTester $tester, string $path): void
    {
        self::write($path, self::capture($tester));
    }

    /**
     * @param array<string, list<array<array-key, mixed>>> $actual
     */
    private static function compare(array $actual, string $path): void
    {
        $expected = self::read($path);

        if ($expected !== $actual) {
            throw new RuntimeException(self::describeDrift($expected, $actual, $path));
        }
    }

    /**
     * `MCP_SNAPSHOT_RECORD` (any value except ''/'0') switches assert()/verify()
     * into record mode — the deliberate regeneration path.
     */
    private static function recordRequested(): bool
    {
        return !in_array(getenv(self::RECORD_ENV), [false, '', '0'], true);
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

        foreach (self::SECTIONS as $section => [$method, $identity]) {
            $items = [];
            /** @var mixed $item */
            foreach ($tester->{$method}() as $item) {
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
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        // a partial write would leave a valid-looking but stale snapshot
        if (@file_put_contents($path, $json) !== strlen($json)) {
            throw new RuntimeException(sprintf('Cannot write MCP schema snapshot to "%s"', $path));
        }
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

        foreach (self::SECTIONS as $section => [1 => $identity]) {
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
            'MCP schema snapshot mismatch at "%s" — %s. If the change is intended, re-record deliberately: re-run with MCP_SNAPSHOT_RECORD=1 (or call SchemaSnapshot::record()) and commit the result',
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
