<?php

declare(strict_types=1);

/**
 * Short annotation name or FQCN => attribute FQCN (same class after migration is typical).
 *
 * @return array<string, string>
 */
return [
    // Doctrine ORM (common aliases)
    'ORM\\Entity' => 'Doctrine\\ORM\\Mapping\\Entity',
    'ORM\\Table' => 'Doctrine\\ORM\\Mapping\\Table',
    'ORM\\Column' => 'Doctrine\\ORM\\Mapping\\Column',
    'ORM\\Id' => 'Doctrine\\ORM\\Mapping\\Id',
    'ORM\\GeneratedValue' => 'Doctrine\\ORM\\Mapping\\GeneratedValue',
    'ORM\\ManyToOne' => 'Doctrine\\ORM\\Mapping\\ManyToOne',
    'ORM\\OneToMany' => 'Doctrine\\ORM\\Mapping\\OneToMany',
    'ORM\\JoinColumn' => 'Doctrine\\ORM\\Mapping\\JoinColumn',

    // Symfony Routing
    'Route' => 'Symfony\\Component\\Routing\\Attribute\\Route',

    // Symfony Validator
    'Assert\\NotBlank' => 'Symfony\\Component\\Validator\\Constraints\\NotBlank',
    'Assert\\NotNull' => 'Symfony\\Component\\Validator\\Constraints\\NotNull',
    'Assert\\Email' => 'Symfony\\Component\\Validator\\Constraints\\Email',
    'Assert\\Length' => 'Symfony\\Component\\Validator\\Constraints\\Length',
];
