<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

/**
 * Lets a configured capability holder opt out of registration at server
 * build time — e.g. gate it behind a feature flag or an environment check.
 * The instance is resolved through the DI container to make the decision,
 * so it can inject whatever it needs (FeatureFlags, config, …).
 *
 * @api
 */
interface ConditionalToolInterface
{
    public function shouldRegister(): bool;
}
