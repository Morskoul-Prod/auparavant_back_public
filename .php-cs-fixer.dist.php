<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->notPath('bootstrap.php')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        // Style déjà employé dans le projet : conservé pour ne pas réécrire tout le code
        'yoda_style' => false,                              // `$x === null`, pas `null === $x`
        'concat_space' => ['spacing' => 'one'],             // `'a' . $b`
        'phpdoc_summary' => false,                          // pas de point final imposé (documentation en français)
        'no_superfluous_phpdoc_tags' => false,              // les docblocks de contrôleurs listent leurs @param / @return
        'phpdoc_align' => false,
        'phpdoc_separation' => false,
        'php_unit_method_casing' => false,                  // tests nommés en camelCase en français
        'increment_style' => false,
        'global_namespace_import' => false,                 // `\DateTimeImmutable` reste écrit avec son antislash
        'fully_qualified_strict_types' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
;
