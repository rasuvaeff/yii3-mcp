<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use Mcp\Schema\Prompt;
use Mcp\Server\Session\SessionInterface;

/**
 * Per-session prompt visibility: filters prompts/list AND fail-closed guards
 * prompts/get — a hidden prompt cannot be fetched even by its exact name
 * (it is reported as not found, indistinguishable from a missing one). One
 * implementation serves both paths so list and get can never disagree.
 *
 * @api
 */
interface PromptVisibilityInterface
{
    public function isVisible(Prompt $prompt, ?SessionInterface $session): bool;
}
