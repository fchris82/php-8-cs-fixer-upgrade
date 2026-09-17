<?php

declare(strict_types=1);

/**
 * Copy to config/phpconverter.php and set paths to your legacy codebase.
 *
 * @return array{
 *     paths: list<string>,
 *     types_map: array<string, string>,
 *     custom_annotations: array<string, string>
 * }
 */
return [
    'paths' => [
        __DIR__ . '/../fixtures',
    ],
    'types_map' => array_merge(
        require __DIR__ . '/types_map.php',
        [
            // 'App\\LegacyDto' => 'App\\Dto',
        ],
    ),
    'custom_annotations' => [
        // 'App\\Annotation\\MyThing' => 'App\\Attribute\\MyThing',
    ],
];
