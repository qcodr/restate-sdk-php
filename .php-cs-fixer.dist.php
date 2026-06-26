<?php

declare(strict_types=1);

/**
 * Strict coding-standard ruleset for the Restate PHP SDK.
 *
 * Builds on PSR-12 and the current PHP migration set, then layers risky rules that
 * enforce strictness the codebase already follows: declared strict types, strict
 * comparisons, strict function parameters, and namespaced native function calls.
 */
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples', __DIR__ . '/conformance'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false, 'import_constants' => false],
        'native_function_invocation' => ['include' => ['@all'], 'scope' => 'all', 'strict' => true],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_superfluous_phpdoc_tags' => false,
        'phpdoc_to_comment' => false,
    ])
    ->setFinder($finder);
