<?php

declare(strict_types=1);

use PhpConverter\Fixer\AnnotationClassToAttributeClassFixer;
use PhpConverter\Fixer\AnnotationToAttributeFixer;
use PhpConverter\Fixer\ReadonlyWhenNeverWrittenFixer;
use PhpConverter\RuleSet\PhpConverterMigrationSet;
use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixerCustomFixers\Fixers as KubawerlosFixers;

$projectConfig = is_file(__DIR__.'/config/phpconverter.php')
    ? require __DIR__.'/config/phpconverter.php'
    : require __DIR__.'/config/phpconverter.dist.php';

$finder = Finder::create()
    ->in($projectConfig['paths'])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$annotationToAttributeFixer = new AnnotationToAttributeFixer();
$annotationToAttributeFixer->configure([
    'annotation_map' => require __DIR__.'/config/annotation_map.php',
    'custom_annotations' => $projectConfig['custom_annotations'] ?? [],
]);

return (new Config())
    ->setRiskyAllowed(true)
    ->registerCustomFixers([
        new AnnotationClassToAttributeClassFixer(),
        $annotationToAttributeFixer,
        new ReadonlyWhenNeverWrittenFixer(),
    ])
    ->registerCustomFixers(new KubawerlosFixers())
    ->registerCustomRuleSets([
        new PhpConverterMigrationSet($projectConfig['types_map'] ?? []),
    ])
    ->setRules([
        '@PhpConverter/migration' => true,
        AnnotationToAttributeFixer::name() => [
            'annotation_map' => require __DIR__.'/config/annotation_map.php',
            'custom_annotations' => $projectConfig['custom_annotations'] ?? [],
        ],
    ])
    ->setFinder($finder);
