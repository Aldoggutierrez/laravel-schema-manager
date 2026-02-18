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
- Four Artisan commands: `MoveTableToSchemaCommand`, `ListTablesCommand`, `MakeSchemaMigration`, and `SchemaDump`

Auto-discovery is configured in `composer.json` under `extra.laravel.providers`.

### Core Commands

**`schema:move-table {table} {--from=} {--to=} {--dry-run} {--force}`**
Moves a PostgreSQL table between schemas. The entire operation runs inside a `DB::transaction()`. Dry-run mode executes but rolls back. The command uses PostgreSQL's `information_schema` views to discover and reconstruct foreign key constraints (dropping them before the move, recreating them after). Options `--from` and `--to` fall back to `config('schema-manager.default_source_schema')` and `config('schema-manager.default_destination_schema')`.

**`schema:list-tables {schema?} {--all}`**
Lists tables in a PostgreSQL schema with sizes via `pg_size_pretty()`. `--all` iterates over all non-system schemas.

**`make:schema-migration {table} {--from=} {--to=}`**
Generates a timestamped migration file in `database/migrations/` that moves a table between schemas via `ALTER TABLE … SET SCHEMA`. The stub includes `up()` and `down()` methods and handles attached sequences. Missing `--from`/`--to` values are collected interactively via `$this->ask()`. Validation rejects empty values or identical source/target schemas. Uses `Illuminate\Filesystem\Filesystem` (injected via constructor) to write the file.

**`schema:dump {--database=pgsql} {--schemas=} {--path=} {--prune}`**
Dumps the PostgreSQL schema DDL and `migrations` table data to a SQL file using `pg_dump` (via Symfony `Process`). Schema list priority: `--schemas` option → connection `search_path` → `'public'`. Default output path is `database/schema/{connection}-schema.sql`. `--prune` deletes all files under `database/migrations/` after the dump. The `dumpSchema` and `dumpMigrationsData` methods are `protected` to allow test doubles to override process execution.

### Testing Approach

Tests use **Pest PHP** with **Orchestra Testbench** (no real database). All `DB` facade calls are mocked with Mockery. Helper functions defined in `tests/Pest.php` reduce boilerplate:
- `mockTableExists()`, `mockTransactionExecutesCallback()`, `mockGetForeignKeys()`, `makeFk()`, etc.

Tests verify command output strings and exit codes via `artisan()` assertions.

**`MakeSchemaMigration`** tests mock `Illuminate\Filesystem\Filesystem` via `$this->app->instance()` so no file is actually written to disk.

**`SchemaDump`** tests use an anonymous class that extends `SchemaDump` and overrides the two `protected` process-execution methods (`dumpSchema`, `dumpMigrationsData`) with no-ops. The anonymous instance is bound to the container via `$this->app->instance(SchemaDump::class, $command)` so Artisan resolves it instead of the real class. This avoids any dependency on `pg_dump` being installed in the test environment. `File` facade calls (for `--prune`) are mocked via `File::shouldReceive()`.

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