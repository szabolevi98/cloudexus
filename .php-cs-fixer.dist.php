<?php

/*
 * A kódstílus: PSR-12, és néhány szabály, amit a kód már most is követ. A CI
 * --dry-run-nal ellenőrzi; helyben a `vendor/bin/php-cs-fixer fix` alkalmazza.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/database', __DIR__ . '/bin', __DIR__ . '/web'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setCacheFile(__DIR__ . '/var/cache/php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,
        // `fn() =>` as the code has always written it, not PSR-12's `fn () =>`.
        'function_declaration' => ['closure_fn_spacing' => 'none'],
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'no_whitespace_in_blank_line' => true,
        'no_extra_blank_lines' => true,
    ])
    ->setFinder($finder);
