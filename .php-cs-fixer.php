<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/examples',
    ]);

return (new Config())
    ->setUsingCache(false)
    ->setRules([
        '@PER-CS3.0' => true,
        '@PHP83Migration' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
    ])
    ->setFinder($finder);
