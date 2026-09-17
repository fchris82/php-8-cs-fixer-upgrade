<?php

declare(strict_types=1);

namespace PhpConverter\RuleSet;

use PhpConverter\Fixer\AnnotationClassToAttributeClassFixer;
use PhpConverter\Fixer\AnnotationToAttributeFixer;
use PhpConverter\Fixer\ReadonlyWhenNeverWrittenFixer;
use PhpCsFixer\RuleSet\RuleSetDefinitionInterface;
use PhpCsFixerCustomFixers\Fixer\PromotedConstructorPropertyFixer;

final class PhpConverterMigrationSet implements RuleSetDefinitionInterface
{
    /**
     * @param array<string, string> $typesMap
     */
    public function __construct(
        private readonly array $typesMap = [],
    ) {
    }

    public function getName(): string
    {
        return '@PhpConverter/migration';
    }

    public function getDescription(): string
    {
        return 'PHP 7/5.6 legacy code migration toward PHP 8.2 (constructors, docblocks, annotations, readonly).';
    }

    public function isRisky(): bool
    {
        return true;
    }

    public function getRules(): array
    {
        $docblockTypes = [
            'scalar_types' => true,
            'union_types' => true,
            'types_map' => $this->typesMap,
        ];

        return [
            '@PHP80Migration' => true,
            '@PHP81Migration' => true,
            '@PHP82Migration' => true,

            AnnotationClassToAttributeClassFixer::name() => true,
            'phpdoc_to_property_type' => true,

            'phpdoc_to_param_type' => $docblockTypes,
            'phpdoc_to_return_type' => $docblockTypes,

            PromotedConstructorPropertyFixer::name() => [
                'promote_only_existing_properties' => true,
            ],
            'multiline_promoted_properties' => [
                'minimum_number_of_parameters' => 2,
            ],

            ReadonlyWhenNeverWrittenFixer::name() => true,
            AnnotationToAttributeFixer::name() => true,

            'no_superfluous_phpdoc_tags' => [
                'allow_mixed' => true,
                'remove_inheritdoc' => false,
            ],
            'no_empty_phpdoc' => true,

            'array_syntax' => ['syntax' => 'short'],
            'lowercase_keywords' => true,
            'visibility_required' => ['elements' => ['property', 'method', 'const']],
            'no_unused_imports' => true,
            'ordered_imports' => ['sort_algorithm' => 'alpha'],
            'class_attributes_separation' => [
                'elements' => [
                    'const' => 'one',
                    'method' => 'one',
                    'property' => 'one',
                    'trait_import' => 'none',
                    'case' => 'none',
                ],
            ],
            'native_type_declaration_casing' => true,
        ];
    }
}
