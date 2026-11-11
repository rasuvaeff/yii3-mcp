<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Firebase\JWT\JWT;

/**
 * An OAuth authorization server's key: the discovery document and the JWKS
 * the SDK's JwtTokenValidator fetches, and RS256 access tokens signed with
 * the matching private key. The HTTP side is a test's own PSR-18 double.
 */
final readonly class FakeIssuer
{
    public const string ISSUER = 'https://auth.example.com';

    public const string DISCOVERY_URL = self::ISSUER . '/.well-known/oauth-authorization-server';

    public const string JWKS_URL = self::ISSUER . '/jwks';

    private const string KID = 'test-key';

    private string $privateKey;

    /** @var array{n: string, e: string} */
    private array $publicKey;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        \assert($key !== false);
        openssl_pkey_export($key, $privateKey);
        \assert(is_string($privateKey));
        $details = openssl_pkey_get_details($key);
        \assert(is_array($details));

        $this->privateKey = $privateKey;
        $this->publicKey = [
            'n' => self::base64Url((string) $details['rsa']['n']),
            'e' => self::base64Url((string) $details['rsa']['e']),
        ];
    }

    /**
     * @param array<string, mixed> $claims merged over a valid default set
     */
    public function token(array $claims = []): string
    {
        $now = time();

        return JWT::encode([
            'iss' => self::ISSUER,
            'aud' => 'https://mcp.example.com/mcp',
            'sub' => 'user-1',
            'client_id' => 'agent-1',
            'iat' => $now,
            'exp' => $now + 600,
            'scope' => 'mcp',
            ...$claims,
        ], $this->privateKey, 'RS256', self::KID);
    }

    public function discoveryDocument(): string
    {
        return json_encode(['issuer' => self::ISSUER, 'jwks_uri' => self::JWKS_URL], JSON_THROW_ON_ERROR);
    }

    public function jwks(): string
    {
        return json_encode([
            'keys' => [[
                'kty' => 'RSA',
                'kid' => self::KID,
                'use' => 'sig',
                'alg' => 'RS256',
                ...$this->publicKey,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
