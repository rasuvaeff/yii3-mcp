<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

/**
 * Masks sensitive tool-call arguments before they leave the process — into an
 * audit trail, a trace span or a log line. A key matching the sensitive list
 * (case-insensitive, at every nesting level) has its whole value replaced
 * with `***`; everything else passes through untouched.
 *
 * One shared helper so every consumer (audit bridge, telemetry bridge,
 * application interceptors) masks with identical semantics instead of
 * drifting apart. The default key list matches
 * rasuvaeff/yii3-audit-log's SensitiveValueMasker.
 *
 * @api
 */
final readonly class ArgumentMasker
{
    private const string MASK = '***';

    private const array DEFAULT_KEYS = [
        'password',
        'secret',
        'token',
        'api_key',
        'credit_card',
    ];

    /** @var list<string> */
    private array $sensitiveKeys;

    /**
     * @param list<string> $sensitiveKeys keys to mask, compared case-insensitively
     */
    public function __construct(array $sensitiveKeys = self::DEFAULT_KEYS)
    {
        $this->sensitiveKeys = array_map(strtolower(...), $sensitiveKeys);
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    public function mask(array $arguments): array
    {
        $masked = [];

        /** @var mixed $value */
        foreach ($arguments as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $masked[$key] = self::MASK;

                continue;
            }

            /** @var mixed */
            $masked[$key] = is_array($value) ? $this->mask($value) : $value;
        }

        return $masked;
    }

    private function isSensitive(string $key): bool
    {
        return in_array(strtolower($key), $this->sensitiveKeys, strict: true);
    }
}
