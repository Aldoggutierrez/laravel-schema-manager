# Laravel Schema Manager

[![Latest Version on Packagist](https://img.shields.io/packagist/v/Aldoggutierrez/laravel-schema-manager.svg?style=flat-square)](https://packagist.org/packages/Aldoggutierrez/laravel-schema-manager)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/Aldoggutierrez/laravel-schema-manager/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Aldoggutierrez/laravel-schema-manager/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/Aldoggutierrez/laravel-schema-manager/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/Aldoggutierrez/laravel-schema-manager/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/Aldoggutierrez/laravel-schema-manager.svg?style=flat-square)](https://packagist.org/packages/Aldoggutierrez/laravel-schema-manager)

Manage PostgreSQL schemas in Laravel applications with ease. Move tables between schemas while preserving foreign keys
and relationships.

## Installation

You can install the package via composer:

```bash
composer require Aldoggutierrez/laravel-schema-manager
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag="schema-manager-config"
```

Available options in `config/schema-manager.php`:

```php
return [
    'default_source_schema' => env('SCHEMA_MANAGER_SOURCE', 'external'),
    'default_destination_schema' => env('SCHEMA_MANAGER_DESTINATION', 'public'),
    'connection' => env('SCHEMA_MANAGER_CONNECTION', null),
    'log_queries' => env('SCHEMA_MANAGER_LOG_QUERIES', false),
];
```

These are the contents of the published config file:

```php
return [
    /*
     * Default source schema when moving tables
     */
    'default_source_schema' => env('SCHEMA_MANAGER_SOURCE', 'external'),

    /*
     * Default destination schema when moving tables
     */
    'default_destination_schema' => env('SCHEMA_MANAGER_DESTINATION', 'public'),

    /*
     * Database connection to use (leave null to use default)
     */
    'connection' => env('SCHEMA_MANAGER_CONNECTION', null),

    /*
     * Enable query logging during operations
     */
    'log_queries' => env('SCHEMA_MANAGER_LOG_QUERIES', false),
];

```

## Usage

### Move a table between schemas

```bash
# Basic usage (uses config defaults)
php artisan schema:move-table authorized_charges

# Specify source and destination
php artisan schema:move-table users --from=external --to=public

# Preview changes without executing
php artisan schema:move-table orders --dry-run

# Skip confirmation prompt
php artisan schema:move-table products --force
```

### List tables in schemas

```bash
# List tables in default schema
php artisan schema:list-tables

# List tables in specific schema
php artisan schema:list-tables external

# List all schemas and their tables
php artisan schema:list-tables --all
```

## Features

✅ Move tables between PostgreSQL schemas  
✅ Automatically handles foreign key constraints  
✅ Preserves all relationships (ON UPDATE/DELETE rules)  
✅ Cross-schema foreign key support  
✅ Dry-run mode to preview changes  
✅ Transaction-based for safety  
✅ List tables and schemas

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Aldoggutierrez](https://github.com/55823142+Aldoggutierrez)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
