<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Identity;

use InvalidArgumentException;

/**
 * Config-backed {@see SecretResolverInterface}: a map of client id to one or
 * several active secrets. Two secrets under one id are how rotation works —
 * add the new secret, roll the clients, remove the old one; both stay valid
 * during the window, and a removed (revoked) secret stops matching
 * immediately.
 *
 * Every comparison uses {@see hash_equals()}; the presented secret is never
 * stored or reported.
 *
 * @api
 */
final readonly class StaticSecretResolver implements SecretResolverInterface
{
    /**
     * @var array<string, list<string>>
     */
    private array $secrets;

    /**
     * @param array<string, string|array<array-key, string>> $secrets Client id => secret or list of active secrets.
     */
    public function __construct(array $secrets)
    {
        if ($secrets === []) {
            throw new InvalidArgumentException('At least one client secret is required');
        }

        $normalized = [];

        foreach ($secrets as $clientId => $clientSecrets) {
            if ($clientId === '') {
                throw new InvalidArgumentException('Client id must not be empty');
            }

            $list = is_string($clientSecrets) ? [$clientSecrets] : array_values($clientSecrets);

            if ($list === []) {
                throw new InvalidArgumentException(sprintf('Client "%s" must have at least one secret', $clientId));
            }

            foreach ($list as $secret) {
                if ($secret === '') {
                    throw new InvalidArgumentException(sprintf('Client "%s" has an empty secret', $clientId));
                }
            }

            $normalized[$clientId] = $list;
        }

        $this->secrets = $normalized;
    }

    #[\Override]
    public function resolve(string $presentedSecret): ?string
    {
        foreach ($this->secrets as $clientId => $clientSecrets) {
            foreach ($clientSecrets as $secret) {
                if (hash_equals($secret, $presentedSecret)) {
                    return $clientId;
                }
            }
        }

        return null;
    }
}
