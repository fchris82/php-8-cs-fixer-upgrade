<?php

declare(strict_types=1);

/**
 * Map legacy / PHPDoc type names to native PHP 8.2 declarations.
 * Extend in config/phpconverter.php.
 */
return [
    'integer' => 'int',
    'boolean' => 'bool',
    'double' => 'float',
    'real' => 'float',
    'callback' => 'callable',
];
