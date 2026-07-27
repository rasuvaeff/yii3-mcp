<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Session;

/**
 * Resolves the effective session directory from configuration: an explicit
 * `session.dir` wins; the default is application-specific (derived from the
 * configured server name) so two applications on one host never share a
 * session directory under the world-writable temp dir — a shared default is
 * both a confidentiality and a session-fixation hazard.
 *
 * @internal
 */
final readonly class SessionDirectory
{
    private function __construct() {}

    public static function resolve(string $configured, string $serverName): string
    {
        if ($configured !== '') {
            return $configured;
        }

        $slug = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $serverName) ?? '', '-');

        return sys_get_temp_dir() . '/yii3-mcp-sessions-'
            . ($slug === '' ? '' : $slug . '-')
            . substr(hash('sha256', $serverName), 0, 16);
    }
}
