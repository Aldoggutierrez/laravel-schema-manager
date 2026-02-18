# Laravel Schema Manager

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aldoggutierrez/laravel-schema-manager.svg?style=flat-square)](https://packagist.org/packages/aldoggutierrez/laravel-schema-manager)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aldoggutierrez/laravel-schema-manager/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/aldoggutierrez/laravel-schema-manager/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aldoggutierrez/laravel-schema-manager/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/aldoggutierrez/laravel-schema-manager/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/aldoggutierrez/laravel-schema-manager.svg?style=flat-square)](https://packagist.org/packages/aldoggutierrez/laravel-schema-manager)

Manage PostgreSQL schemas in Laravel applications with ease. Move tables between schemas while preserving foreign keys
and relationships. Generate versioned schema migrations and dump the full database schema to a SQL file.

## Installation

You can install the package via composer:

```bash
composer require aldoggutierrez/laravel-schema-manager
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

### Generate a schema migration

Creates a versioned migration file that moves a table between schemas using
`ALTER TABLE … SET SCHEMA`. The generated migration also handles any sequences
attached to the table.

```bash
# Specify schemas explicitly
php artisan make:schema-migration orders --from=external --to=public

# Omit options to be prompted interactively
php artisan make:schema-migration orders
```

The generated file is placed in `database/migrations/` with a timestamped name
such as `2024_01_01_120000_move_orders_from_external_to_public.php`. It contains
both `up()` and `down()` methods so the move is fully reversible.

### Dump the database schema

Exports the PostgreSQL schema (DDL) for one or more schemas to a SQL file using
`pg_dump`. Useful for keeping a schema snapshot in version control and for
seeding fresh environments without running every historical migration.

> **Requires** `pg_dump` to be installed and accessible on `$PATH`.

```bash
# Dump the default pgsql connection (uses search_path, falls back to public)
php artisan schema:dump

# Specify a custom connection
php artisan schema:dump --database=tenant

# Dump specific schemas
php artisan schema:dump --schemas=billing,reports

# Write to a custom path
php artisan schema:dump --path=/tmp/schema.sql

# Prune existing migration files after dumping
php artisan schema:dump --prune
```

**Options**

| Option | Default | Description |
|---|---|---|
| `--database` | `pgsql` | Laravel database connection to use |
| `--schemas` | connection `search_path` or `public` | Comma-separated list of schemas to dump |
| `--path` | `database/schema/{connection}-schema.sql` | Output file path |
| `--prune` | — | Delete all files in `database/migrations/` after the dump |

The command writes two sections to the output file:

1. Schema-only DDL (`pg_dump --schema-only`) for all requested schemas.
2. Data rows from the `migrations` table so Laravel knows which migrations have
   already been run.

## Features

✅ Move tables between PostgreSQL schemas
✅ Automatically handles foreign key constraints
✅ Preserves all relationships (ON UPDATE/DELETE rules)
✅ Cross-schema foreign key support
✅ Dry-run mode to preview changes
✅ Transaction-based for safety
✅ List tables and schemas
✅ Generate reversible schema migration files
✅ Dump the full schema (DDL + migrations data) to a SQL file

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
