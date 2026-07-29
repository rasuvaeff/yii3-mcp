<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Apps;

use Closure;
use InvalidArgumentException;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiResourcePermissions;

/**
 * Converts one `apps.definitions` entry of the params array into an
 * {@see AppDefinition}.
 *
 * The SDK's own `UiResourceCsp::fromArray()` / `UiResourcePermissions::fromArray()`
 * are deliberately NOT used, because both would misread this params format
 * SILENTLY:
 *
 * - permissions treat a PRESENT key as "requested" (`isset()`), so the
 *   perfectly natural `'camera' => false` would switch the camera ON;
 * - CSP reads camelCase keys (`connectDomains`), while params use snake_case
 *   (`connect_domains`) like every other option in this package — every field
 *   would come out `null`, no CSP would be emitted, and the operator would
 *   believe a domain was allow-listed when the host actually applied its own
 *   restrictive default.
 *
 * Domains are passed through verbatim: the policy is enforced by the client
 * host, and `definitions` is application-owned configuration, not client
 * input.
 *
 * @internal wired by `config/di.php`
 */
final readonly class AppParamParser
{
    /**
     * @param array<string, mixed> $app
     */
    public static function parse(array $app): AppDefinition
    {
        return AppDefinition::create(
            uri: self::requiredString($app, 'uri'),
            name: self::requiredString($app, 'name'),
            html: self::html($app),
            title: self::optionalString($app, 'title'),
            description: self::optionalString($app, 'description'),
            contentMeta: self::contentMeta($app),
        );
    }

    /**
     * @param array<string, mixed> $app
     *
     * @return string|Closure(): string
     */
    private static function html(array $app): string|Closure
    {
        /** @var mixed $html */
        $html = $app['html'] ?? null;

        if (is_string($html)) {
            return $html;
        }

        if ($html instanceof Closure) {
            /** @var Closure(): string $html */
            return $html;
        }

        throw new InvalidArgumentException(sprintf('App "html" must be a string or a Closure returning a string, %s given', get_debug_type($html)));
    }

    /**
     * @param array<string, mixed> $app
     */
    private static function contentMeta(array $app): ?UiResourceContentMeta
    {
        $csp = self::csp($app['csp'] ?? null);
        $permissions = self::permissions($app['permissions'] ?? null);
        $domain = self::optionalString($app, 'domain');
        /** @var mixed $prefersBorder */
        $prefersBorder = $app['prefers_border'] ?? null;

        if (!$csp instanceof UiResourceCsp && !$permissions instanceof UiResourcePermissions && $domain === null && $prefersBorder === null) {
            return null;
        }

        return new UiResourceContentMeta(
            csp: $csp,
            permissions: $permissions,
            domain: $domain,
            prefersBorder: is_bool($prefersBorder) ? $prefersBorder : null,
        );
    }

    private static function csp(mixed $csp): ?UiResourceCsp
    {
        if (!is_array($csp) || $csp === []) {
            return null;
        }

        return new UiResourceCsp(
            connectDomains: self::domains($csp['connect_domains'] ?? null),
            resourceDomains: self::domains($csp['resource_domains'] ?? null),
            frameDomains: self::domains($csp['frame_domains'] ?? null),
            baseUriDomains: self::domains($csp['base_uri_domains'] ?? null),
        );
    }

    private static function permissions(mixed $permissions): ?UiResourcePermissions
    {
        if (!is_array($permissions) || $permissions === []) {
            return null;
        }

        return new UiResourcePermissions(
            camera: (bool) ($permissions['camera'] ?? false),
            microphone: (bool) ($permissions['microphone'] ?? false),
            geolocation: (bool) ($permissions['geolocation'] ?? false),
            clipboardWrite: (bool) ($permissions['clipboard_write'] ?? false),
        );
    }

    /**
     * @return ?list<string>
     */
    private static function domains(mixed $domains): ?array
    {
        if (!is_array($domains) || $domains === []) {
            return null;
        }

        $list = [];

        /** @var mixed $domain */
        foreach ($domains as $domain) {
            if (!is_string($domain)) {
                throw new InvalidArgumentException(sprintf('App CSP domains must be strings, %s given', get_debug_type($domain)));
            }

            $list[] = $domain;
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $app
     */
    private static function requiredString(array $app, string $key): string
    {
        /** @var mixed $value */
        $value = $app[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('App "%s" must be a non-empty string', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $app
     */
    private static function optionalString(array $app, string $key): ?string
    {
        /** @var mixed $value */
        $value = $app[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
