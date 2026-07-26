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
 * drifting apart. The default key list starts from rasuvaeff/yii3-audit-log's
 * SensitiveValueMasker list, then adds the credential/token spellings its
 * exact-match comparison would otherwise miss: bare `pass`/`pwd`/`auth`,
 * bearer/JWT tokens (`bearer`, `jwt`), session/cookie carriers (`cookie`,
 * `session_token`), API keys (`apikey`, `api-key`, `x-api-key`), OAuth-style
 * pairs (`access_token`, `access-token`, `id_token`, `auth_token`,
 * `refresh_token`, `client_secret`), `private_key` and `authorization`.
 * Masking more than audit-log is the safe direction — an over-masked field
 * only costs log fidelity, an under-masked one leaks a credential. Comparison
 * is case-insensitive but exact, so `apikey` covers `ApiKey` while
 * `apiKeyHeader` stays visible: pass your own list to cover
 * application-specific names.
 *
 * @api
 */
final readonly class ArgumentMasker
{
    private const string MASK = '***';

    private const array DEFAULT_KEYS = [
        'password',
        'pass',
        'pwd',
        'secret',
        'token',
        'auth',
        'authorization',
        'bearer',
        'jwt',
        'cookie',
        'api_key',
        'apikey',
        'api-key',
        'x-api-key',
        'access_token',
        'accesstoken',
        'access-token',
        'id_token',
        'idtoken',
        'session_token',
        'sessiontoken',
        'auth_token',
        'authtoken',
        'refresh_token',
        'refreshtoken',
        'client_secret',
        'clientsecret',
        'private_key',
        'privatekey',
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
