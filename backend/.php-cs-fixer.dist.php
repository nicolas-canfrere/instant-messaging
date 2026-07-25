<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        'config',
        'public',
        'src',
        'tests',
    ])
    ->notPath('reference.php')
;

$config = new PhpCsFixer\Config();

$config
    ->setUsingCache(false)
    ->setRiskyAllowed(true)
    ->setRules(
        [
            '@Symfony' => true,
            '@PSR2' => true,
            'array_syntax' => ['syntax' => 'short'],
            'binary_operator_spaces' => [
                'default' => 'single_space',
            ],
            'combine_consecutive_issets' => true,
            'combine_consecutive_unsets' => true,
            'concat_space' => ['spacing' => 'one'],
            'declare_strict_types' => true,
            'linebreak_after_opening_tag' => true,
            'list_syntax' => ['syntax' => 'short'],
            'no_alternative_syntax' => true,
            'no_unreachable_default_argument_value' => true,
            'no_unused_imports' => true,
            'no_superfluous_elseif' => true,
            'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
            'no_useless_else' => true,
            'no_useless_return' => true,
            'ordered_class_elements' => true,
            'ordered_imports' => true,
            'semicolon_after_instruction' => true,
            'ternary_to_null_coalescing' => true,
            'void_return' => true,
            'phpdoc_to_property_type' => true,
            'function_declaration' => [
                'closure_fn_spacing' => 'none',
            ],
            'single_line_throw' => false,
            'php_unit_method_casing' => false,
            'nullable_type_declaration_for_default_null_value' => true,
            'phpdoc_align' => false,
            'phpdoc_to_comment' => false,
        ]
    )->setFinder($finder);

return $config;
