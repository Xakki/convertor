<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                         => true,
        '@PHP81Migration'                => true,
        'declare_strict_types'           => true,
        'array_syntax'                   => ['syntax' => 'short'],
        'ordered_imports'                => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'              => true,
        'trailing_comma_in_multiline'    => true,
        'phpdoc_scalar'                  => true,
        'unary_operator_spaces'          => true,
        'binary_operator_spaces'         => ['default' => 'align_single_space_minimal'],
        'blank_line_before_statement'    => ['statements' => ['return', 'throw', 'try']],
        'concat_space'                   => ['spacing' => 'one'],
        'not_operator_with_successor_space' => true,
    ])
    ->setFinder($finder)
;
