<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\DeadCode\Rector\StmtsAwareInterface\RemoveDeadInstanceOfAssertRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([AddNameToLiteralArgumentRector::class])
    ->withSkip([
        // psalm level 1 (MixedAssignment) requires the /** @var mixed */ tags
        // these rules strip
        RemoveNonExistingVarAnnotationRector::class,
        RemoveUselessVarTagRector::class,
        // stream wrappers are invoked through PHP's runtime protocol
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/tests/Support/ThrowingStreamWrapper.php',
        ],
        // the constructor attribute is the behavior under test
        RemoveEmptyClassMethodRector::class => [
            __DIR__ . '/tests/Support/ConstructorAttributeTool.php',
        ],
        // Testo's assertion is not understood by Psalm's type narrowing
        RemoveDeadInstanceOfAssertRector::class => [
            __DIR__ . '/tests/Apps/AppResourceHandlerTest.php',
            __DIR__ . '/tests/Resource/ResourceUpdateNotifierTest.php',
        ],
    ]);
