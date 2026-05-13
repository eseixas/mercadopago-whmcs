<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer Configuration - Mercado Pago WHMCS
 *
 * Documentacao: https://cs.symfony.com/doc/rules/index.html
 * Comandos:
 *   composer cs:check  - Verifica (dry-run)
 *   composer cs:fix    - Corrige automaticamente
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/modules',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->exclude([
        'vendor',
        'build',
        'node_modules',
        'tests/fixtures',
    ])
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setLineEnding("\n")
    ->setIndent('    ')
    ->setFinder($finder)
    ->setRules([
        // ============================================================
        // Presets base
        // ============================================================
        '@PSR12'                              => true,
        '@PSR12:risky'                        => true,
        '@PHP82Migration'                     => true,
        '@PHP80Migration:risky'               => true,
        '@PhpCsFixer'                         => true,
        '@PhpCsFixer:risky'                   => true,

        // ============================================================
        // Imports / Namespaces
        // ============================================================
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order'  => ['class', 'function', 'const'],
        ],
        'no_unused_imports'                   => true,
        'no_useless_else'                     => true,
        'no_useless_return'                   => true,
        'single_line_after_imports'           => true,
        'global_namespace_import' => [
            'import_classes'   => true,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // ============================================================
        // Strict types & types declaration
        // ============================================================
        'declare_strict_types'                => true,
        'strict_param'                        => true,
        'strict_comparison'                   => true,
        'void_return'                         => true,
        'return_type_declaration'             => ['space_before' => 'none'],
        'fully_qualified_strict_types'        => true,

        // ============================================================
        // Arrays
        // ============================================================
        'array_syntax'                        => ['syntax' => 'short'],
        'trim_array_spaces'                   => true,
        'no_multiline_whitespace_around_double_arrow' => true,
        'whitespace_after_comma_in_array'     => true,
        'normalize_index_brace'               => true,

        // ============================================================
        // PHPDoc
        // ============================================================
        'phpdoc_align' => [
            'align' => 'vertical',
            'tags'  => ['param', 'return', 'throws', 'type', 'var'],
        ],
        'phpdoc_order'                        => true,
        'phpdoc_separation'                   => true,
        'phpdoc_trim'                         => true,
        'phpdoc_summary'                      => false,
        'phpdoc_to_comment'                   => false,
        'phpdoc_no_empty_return'              => false,
        'phpdoc_no_useless_inheritdoc'        => true,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed'                  => true,
            'remove_inheritdoc'            => false,
            'allow_unused_params'          => false,
        ],

        // ============================================================
        // Espacos em branco
        // ============================================================
        'no_extra_blank_lines' => [
            'tokens' => [
                'extra',
                'throw',
                'use',
                'use_trait',
                'curly_brace_block',
                'parenthesis_brace_block',
                'square_brace_block',
            ],
        ],
        'no_whitespace_in_blank_line'         => true,
        'no_trailing_whitespace'              => true,
        'no_trailing_whitespace_in_comment'   => true,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try', 'if', 'foreach', 'for', 'while'],
        ],

        // ============================================================
        // Strings
        // ============================================================
        'single_quote'                        => true,
        'string_implicit_backslashes'         => true,
        'explicit_string_variable'            => true,

        // ============================================================
        // Modernizacao PHP 8.x
        // ============================================================
        'modernize_strpos'                    => true,
        'modernize_types_casting'             => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'use_arrow_functions'                 => true,
        'get_class_to_class_keyword'          => true,

        // ============================================================
        // Classes
        // ============================================================
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
        'final_internal_class'                => true,
        'self_accessor'                       => true,
        'self_static_accessor'                => true,
        'protected_to_private'                => true,
        'visibility_required'                 => ['elements' => ['property', 'method', 'const']],

        // ============================================================
        // Operadores e estrutura de controle
        // ============================================================
        'concat_space'                        => ['spacing' => 'one'],
        'binary_operator_spaces'              => ['default' => 'single_space'],
        'ternary_to_null_coalescing'          => true,
        'simplified_null_return'              => true,
        'no_alternative_syntax'               => true,

        // ============================================================
        // Comentarios
        // ============================================================
        'single_line_comment_style'           => ['comment_types' => ['hash']],
        'multiline_comment_opening_closing'   => true,

        // ============================================================
        // Seguranca
        // ============================================================
        'random_api_migration'                => true,
        'set_type_to_cast'                    => true,
        'no_alias_functions'                  => true,
        'is_null'                             => true,

        // ============================================================
        // Performance
        // ============================================================
        'native_function_invocation' => [
            'include'  => ['@compiler_optimized'],
            'scope'    => 'namespaced',
            'strict'   => true,
        ],
        'native_constant_invocation'          => true,
    ]);
