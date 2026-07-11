<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSkip([
        // psalm level 1 (MixedAssignment) requires the /** @var mixed */ tags
        // these rules strip
        RemoveNonExistingVarAnnotationRector::class,
        RemoveUselessVarTagRector::class,
    ]);
