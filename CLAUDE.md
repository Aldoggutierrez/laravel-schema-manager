# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Run tests with coverage
composer test-coverage

# Run a single test file
./vendor/bin/pest tests/Commands/MoveTableToSchemaCommandTest.php

# Run a single test by description
./vendor/bin/pest --filter="it moves a table successfully"

# Static analysis
composer analyse

# Format code (Laravel Pint)
composer format
```

## Architecture

This is a **Laravel package** (not a full Laravel app) that provides Artisan commands for managing PostgreSQL schemas. It is built using `spatie/laravel-package-tools`.

### Package Bootstrap

The `LaravelSchemaManagerServiceProvider` (extends Spatie's `PackageServiceProvider`) registers:
- The `schema-manager` config file (`config/schema-manager.php`)
- Two Artisan commands: `MoveTableToSchemaCommand` and `ListTablesCommand`

Auto-discovery is configured in `composer.json` under `extra.laravel.providers`.

### Core Commands

**`schema:move-table {table} {--from=} {--to=} {--dry-run} {--force}`**
Moves a PostgreSQL table between schemas. The entire operation runs inside a `DB::transaction()`. Dry-run mode executes but rolls back. The command uses PostgreSQL's `information_schema` views to discover and reconstruct foreign key constraints (dropping them before the move, recreating them after). Options `--from` and `--to` fall back to `config('schema-manager.default_source_schema')` and `config('schema-manager.default_destination_schema')`.

**`schema:list-tables {schema?} {--all}`**
Lists tables in a PostgreSQL schema with sizes via `pg_size_pretty()`. `--all` iterates over all non-system schemas.

### Testing Approach

Tests use **Pest PHP** with **Orchestra Testbench** (no real database). All `DB` facade calls are mocked with Mockery. Helper functions defined in `tests/Pest.php` reduce boilerplate:
- `mockTableExists()`, `mockTransactionExecutesCallback()`, `mockGetForeignKeys()`, `makeFk()`, etc.

Tests verify command output strings and exit codes via `artisan()` assertions.

**Architecture test** (`tests/ArchTest.php`) enforces that `dd`, `dump`, and `ray` are never used in `src/`.

### Supported Versions

- PHP 8.2+
- Laravel 10, 11, 12 (matched with Testbench 8, 9, 10 respectively)
- PostgreSQL only (relies on `information_schema` and `pg_size_pretty`)

### Configuration

```php
// config/schema-manager.php
'default_source_schema'      => env('SCHEMA_MANAGER_SOURCE', 'external'),
'default_destination_schema' => env('SCHEMA_MANAGER_DESTINATION', 'public'),
'connection'                 => env('SCHEMA_MANAGER_CONNECTION', null),
'log_queries'                => env('SCHEMA_MANAGER_LOG_QUERIES', false),
```

### CI

GitHub Actions runs tests across a matrix of OS (Ubuntu, Windows), PHP (8.2–8.4), and Laravel (10–12) versions. PHPStan runs separately at level 5. Laravel Pint auto-commits style fixes.