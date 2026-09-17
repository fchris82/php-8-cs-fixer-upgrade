# phpconverter

Migrate legacy PHP 5.6/7 code toward **PHP 8.2** using [PHP CS Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) with custom rules and a bundled ruleset `@PhpConverter/migration`.

## Requirements

- PHP 8.2+
- Composer

## Installation

```bash
composer install
```

Copy [`config/phpconverter.dist.php`](config/phpconverter.dist.php) to `config/phpconverter.php` and set `paths` to your legacy project directories.

## Usage

Dry run:

```bash
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
```

Apply fixes (risky rules enabled in config):

```bash
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes
```

Or:

```bash
composer fix
```

## What the ruleset does

| Area | Mechanism |
|------|-----------|
| Constructor promotion | `kubawerlos` `PromotedConstructorPropertyFixer` (`promote_only_existing_properties`) |
| Readonly properties | Custom `PhpConverter/readonly_when_never_written` |
| `@param` / `@return` → types | Core `phpdoc_to_param_type`, `phpdoc_to_return_type` |
| Docblock cleanup | `no_superfluous_phpdoc_tags`, `no_empty_phpdoc` |
| Annotations → attributes | Custom `PhpConverter/annotation_to_attribute` |
| Annotation classes → attribute classes | Custom `PhpConverter/annotation_class_to_attribute_class` |
| PHP 8.x syntax | `@PHP80Migration`, `@PHP81Migration`, `@PHP82Migration` (verify with `vendor/bin/php-cs-fixer list-sets`) |

## Configuration

- **`types_map`** — merged into docblock type fixers ([`config/types_map.php`](config/types_map.php)).
- **`custom_annotations`** — map annotation FQCN to attribute FQCN in `config/phpconverter.php`.
- **`annotation_map`** — default Doctrine/Symfony short names ([`config/annotation_map.php`](config/annotation_map.php)).

Example `config/phpconverter.php`:

```php
<?php

return [
    'paths' => ['/path/to/legacy/src'],
    'types_map' => require __DIR__.'/types_map.php',
    'custom_annotations' => [
        'App\\Annotation\\Legacy' => 'App\\Attribute\\Legacy',
    ],
];
```

## Custom fixers

| Rule name | Description |
|-----------|-------------|
| `PhpConverter/readonly_when_never_written` | Adds `readonly` when a typed property is never assigned outside simple constructor wiring |
| `PhpConverter/annotation_to_attribute` | Converts configured docblock annotations to `#[...]` |
| `PhpConverter/annotation_class_to_attribute_class` | Converts `@Annotation` definition classes to `#[\Attribute]` + promoted constructor |

## Tests

```bash
composer test
```

## Limits

- Docblock types are only applied when `@param` / `@return` are present and compatible; `mixed` and complex generics may remain in docblocks.
- Constructor promotion applies to straightforward `$this->prop = $param` patterns.
- Annotation conversion skips values that look like runtime expressions (`$var`, `->`, `new`).
- Readonly detection is token-based and may miss indirect mutations.

## Risky rules

The migration ruleset is marked risky. Review diffs and run your test suite before merging.
