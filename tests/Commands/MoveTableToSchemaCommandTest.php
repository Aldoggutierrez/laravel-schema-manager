<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

function mockTableExists(string $schema, string $table, bool $exists): void
{
    DB::shouldReceive('selectOne')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'information_schema.tables')
            && $bindings === [$schema, $table])
        ->andReturn((object) ['exists' => $exists]);
}

function mockSchemaExists(string $schema, bool $exists): void
{
    DB::shouldReceive('selectOne')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'information_schema.schemata')
            && $bindings === [$schema])
        ->andReturn((object) ['exists' => $exists]);
}

function mockCreateSchema(string $schema): void
{
    DB::shouldReceive('statement')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, "CREATE SCHEMA IF NOT EXISTS $schema"));
}

function mockTransactionExecutesCallback(): void
{
    DB::shouldReceive('transaction')
        ->once()
        ->andReturnUsing(fn ($callback) => $callback());
}

function mockCreateTempTable(): void
{
    DB::shouldReceive('statement')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, 'CREATE TEMP TABLE'));
}

function mockGetForeignKeys(string $schema, string $table, array $fks = []): void
{
    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'table_constraints')
            && $bindings === [$schema, $table])
        ->andReturn($fks);
}

function mockAlterTableSetSchema(string $schemaFrom, string $table, string $schemaTo): void
{
    DB::shouldReceive('statement')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, "ALTER TABLE $schemaFrom.$table SET SCHEMA $schemaTo"));
}

function mockSelectSavedFks(array $fks = []): void
{
    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, 'temp_move_fks'))
        ->andReturn($fks);
}

function makeFk(
    string $constraintName,
    string $columnName,
    string $foreignSchema,
    string $foreignTable,
    string $foreignColumn,
    string $updateRule = 'NO ACTION',
    string $deleteRule = 'CASCADE'
): object {
    return (object) [
        'constraint_name' => $constraintName,
        'column_name' => $columnName,
        'foreign_schema' => $foreignSchema,
        'foreign_table' => $foreignTable,
        'foreign_column' => $foreignColumn,
        'update_rule' => $updateRule,
        'delete_rule' => $deleteRule,
    ];
}

it('moves a table successfully with --force flag', function () {
    mockTableExists('external', 'orders', true);
    mockSchemaExists('public', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockAlterTableSetSchema('external', 'orders', 'public');
    mockSelectSavedFks([]);

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--force' => true,
    ])
        ->expectsOutputToContain('orders')
        ->expectsOutputToContain('external')
        ->expectsOutputToContain('public')
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('fails when the table does not exist', function () {
    mockTableExists('external', 'nonexistent', false);

    $this->artisan('schema:move-table', [
        'table' => 'nonexistent',
        '--from' => 'external',
        '--to' => 'public',
        '--force' => true,
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('shows dry-run preview without executing changes', function () {
    mockTableExists('external', 'orders', true);
    mockSchemaExists('public', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();

    $fk = makeFk('fk_order_user', 'user_id', 'public', 'users', 'id');
    mockGetForeignKeys('external', 'orders', [$fk]);

    DB::shouldReceive('insert')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, 'temp_move_fks'));

    mockSelectSavedFks([$fk]);

    DB::shouldReceive('rollBack')->once();

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('DRY RUN MODE')
        ->expectsOutputToContain('Would execute')
        ->expectsOutputToContain('Would drop FK: fk_order_user')
        ->expectsOutputToContain('Would recreate FK: fk_order_user')
        ->expectsOutputToContain('DRY RUN completed')
        ->assertSuccessful();
});

it('shows would-create-schema in dry-run when schema does not exist', function () {
    mockTableExists('external', 'orders', true);
    mockSchemaExists('public', false);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockSelectSavedFks([]);

    DB::shouldReceive('rollBack')->once();

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('DRY RUN MODE')
        ->expectsOutputToContain("Would create schema 'public'")
        ->expectsOutputToContain('DRY RUN completed')
        ->assertSuccessful();
});

it('asks for confirmation and proceeds when user confirms', function () {
    mockTableExists('external', 'orders', true);
    mockSchemaExists('public', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockAlterTableSetSchema('external', 'orders', 'public');
    mockSelectSavedFks([]);

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
    ])
        ->expectsConfirmation('Do you want to proceed?', 'yes')
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('cancels when user declines confirmation', function () {
    mockTableExists('external', 'orders', true);

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
    ])
        ->expectsConfirmation('Do you want to proceed?', 'no')
        ->expectsOutputToContain('Operation cancelled')
        ->assertSuccessful();
});

it('creates destination schema when it does not exist and user confirms', function () {
    mockTableExists('external', 'orders', true);
    mockSchemaExists('newschema', false);
    mockCreateSchema('newschema');
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockAlterTableSetSchema('external', 'orders', 'newschema');
    mockSelectSavedFks([]);

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'newschema',
    ])
        ->expectsConfirmation('Do you want to proceed?', 'yes')
        ->expectsConfirmation("Schema 'newschema' does not exist. Do you want to create it?", 'yes')
        ->expectsOutputToContain("Created schema 'newschema'")
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('cancels when user declines schema creation', function () {
    mockTableExists('external', 'orders', true);
    mockSchemaExists('newschema', false);

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'newschema',
    ])
        ->expectsConfirmation('Do you want to proceed?', 'yes')
        ->expectsConfirmation("Schema 'newschema' does not exist. Do you want to create it?", 'no')
        ->expectsOutputToContain('Operation cancelled')
        ->assertSuccessful();
});

it('creates destination schema without confirmation when --force is used', function () {
    mockTableExists('external', 'orders', true);
    mockSchemaExists('newschema', false);
    mockCreateSchema('newschema');
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockAlterTableSetSchema('external', 'orders', 'newschema');
    mockSelectSavedFks([]);

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'newschema',
        '--force' => true,
    ])
        ->expectsOutputToContain("Created schema 'newschema'")
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('uses config defaults for --from and --to when not provided', function () {
    config()->set('schema-manager.default_source_schema', 'source_test');
    config()->set('schema-manager.default_destination_schema', 'dest_test');

    mockTableExists('source_test', 'users', true);
    mockSchemaExists('dest_test', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('source_test', 'users', []);
    mockAlterTableSetSchema('source_test', 'users', 'dest_test');
    mockSelectSavedFks([]);

    $this->artisan('schema:move-table', [
        'table' => 'users',
        '--force' => true,
    ])
        ->expectsOutputToContain('source_test')
        ->expectsOutputToContain('dest_test')
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('handles foreign keys during table move', function () {
    $fk1 = makeFk('fk_order_user', 'user_id', 'public', 'users', 'id');
    $fk2 = makeFk('fk_order_product', 'product_id', 'public', 'products', 'id', 'CASCADE', 'SET NULL');

    mockTableExists('external', 'orders', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', [$fk1, $fk2]);

    // Drop FK constraints
    DB::shouldReceive('statement')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, 'DROP CONSTRAINT fk_order_user'));

    DB::shouldReceive('statement')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, 'DROP CONSTRAINT fk_order_product'));

    // Insert FK data into temp table
    DB::shouldReceive('insert')
        ->twice()
        ->withArgs(fn ($query) => str_contains($query, 'temp_move_fks'));

    // Move table
    mockAlterTableSetSchema('external', 'orders', 'public');

    // Recreate FKs
    mockSelectSavedFks([$fk1, $fk2]);

    DB::shouldReceive('statement')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, 'ADD CONSTRAINT fk_order_user'));

    DB::shouldReceive('statement')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, 'ADD CONSTRAINT fk_order_product'));

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--force' => true,
    ])
        ->expectsOutputToContain('Found 2 foreign key(s)')
        ->expectsOutputToContain('Dropped FK: fk_order_user')
        ->expectsOutputToContain('Dropped FK: fk_order_product')
        ->expectsOutputToContain('Recreated FK: fk_order_user')
        ->expectsOutputToContain('Recreated FK: fk_order_product')
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('handles errors gracefully', function () {
    mockTableExists('external', 'orders', true);
    mockSchemaExists('public', true);

    DB::shouldReceive('transaction')
        ->once()
        ->andThrow(new RuntimeException('Connection lost'));

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--force' => true,
    ])
        ->expectsOutputToContain('Connection lost')
        ->assertFailed();
});

it('uses explicit --from and --to options over config defaults', function () {
    config()->set('schema-manager.default_source_schema', 'default_source');
    config()->set('schema-manager.default_destination_schema', 'default_dest');

    mockTableExists('custom_source', 'users', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('custom_source', 'users', []);
    mockAlterTableSetSchema('custom_source', 'users', 'custom_dest');
    mockSelectSavedFks([]);

    $this->artisan('schema:move-table', [
        'table' => 'users',
        '--from' => 'custom_source',
        '--to' => 'custom_dest',
        '--force' => true,
    ])
        ->expectsOutputToContain('custom_source')
        ->expectsOutputToContain('custom_dest')
        ->doesntExpectOutputToContain('default_source')
        ->doesntExpectOutputToContain('default_dest')
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

// --- Model $table property update tests ---

it('sets $table property when moving away from default schema', function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing.search_path', 'external,public');

    mockTableExists('external', 'orders', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockAlterTableSetSchema('external', 'orders', 'public');
    mockSelectSavedFks([]);

    $modelContent = modelWithoutTable('Order');

    File::shouldReceive('exists')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn($modelContent);

    File::shouldReceive('put')
        ->once()
        ->withArgs(fn ($path, $content) => str_ends_with($path, 'Models/Order.php')
            && str_contains($content, "protected \$table = 'public.orders';"));

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--force' => true,
    ])
        ->expectsOutputToContain("Added \$table = 'public.orders'")
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('removes $table property when moving to default schema', function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing.search_path', 'external,public');

    mockTableExists('public', 'orders', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('public', 'orders', []);
    mockAlterTableSetSchema('public', 'orders', 'external');
    mockSelectSavedFks([]);

    $modelContent = modelWithTable('Order', 'public.orders');

    File::shouldReceive('exists')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn($modelContent);

    File::shouldReceive('put')
        ->once()
        ->withArgs(fn ($path, $content) => str_ends_with($path, 'Models/Order.php')
            && ! str_contains($content, 'protected $table'));

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'public',
        '--to' => 'external',
        '--force' => true,
    ])
        ->expectsOutputToContain('Removed $table property from model')
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('updates existing $table value when moving between non-default schemas', function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing.search_path', 'external,public');

    mockTableExists('public', 'orders', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('public', 'orders', []);
    mockAlterTableSetSchema('public', 'orders', 'staging');
    mockSelectSavedFks([]);

    $modelContent = modelWithTable('Order', 'public.orders');

    File::shouldReceive('exists')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn($modelContent);

    File::shouldReceive('put')
        ->once()
        ->withArgs(fn ($path, $content) => str_ends_with($path, 'Models/Order.php')
            && str_contains($content, "protected \$table = 'staging.orders';"));

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'public',
        '--to' => 'staging',
        '--force' => true,
    ])
        ->expectsOutputToContain("Updated \$table = 'staging.orders'")
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('shows model changes in dry-run without modifying file', function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing.search_path', 'external,public');

    mockTableExists('external', 'orders', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockSelectSavedFks([]);

    DB::shouldReceive('rollBack')->once();

    File::shouldReceive('exists')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn(true);

    // File::get and File::put should NOT be called in dry-run
    File::shouldNotReceive('get');
    File::shouldNotReceive('put');

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain("Would set \$table = 'public.orders'")
        ->expectsOutputToContain('DRY RUN completed')
        ->assertSuccessful();
});

it('warns but succeeds when model file is not found', function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing.search_path', 'external,public');

    mockTableExists('external', 'orders', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockAlterTableSetSchema('external', 'orders', 'public');
    mockSelectSavedFks([]);

    File::shouldReceive('exists')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn(false);

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--force' => true,
    ])
        ->expectsOutputToContain('Model file not found')
        ->expectsOutputToContain('Table moved successfully')
        ->assertSuccessful();
});

it('resolves model from --model option', function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing.search_path', 'external,public');

    mockTableExists('external', 'orders', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockAlterTableSetSchema('external', 'orders', 'public');
    mockSelectSavedFks([]);

    $modelContent = modelWithoutTable('CustomOrder');

    File::shouldReceive('exists')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/CustomOrder.php'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/CustomOrder.php'))
        ->andReturn($modelContent);

    File::shouldReceive('put')
        ->once()
        ->withArgs(fn ($path, $content) => str_ends_with($path, 'Models/CustomOrder.php')
            && str_contains($content, "protected \$table = 'public.orders';"));

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--model' => 'App\\Models\\CustomOrder',
        '--force' => true,
    ])
        ->expectsOutputToContain("Added \$table = 'public.orders'")
        ->assertSuccessful();
});

it('always sets $table when no search_path is configured', function () {
    config()->set('database.default', 'testing');
    // No search_path configured

    mockTableExists('external', 'orders', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'orders', []);
    mockAlterTableSetSchema('external', 'orders', 'public');
    mockSelectSavedFks([]);

    $modelContent = modelWithoutTable('Order');

    File::shouldReceive('exists')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/Order.php'))
        ->andReturn($modelContent);

    File::shouldReceive('put')
        ->once()
        ->withArgs(fn ($path, $content) => str_ends_with($path, 'Models/Order.php')
            && str_contains($content, "protected \$table = 'public.orders';"));

    $this->artisan('schema:move-table', [
        'table' => 'orders',
        '--from' => 'external',
        '--to' => 'public',
        '--force' => true,
    ])
        ->expectsOutputToContain("Added \$table = 'public.orders'")
        ->assertSuccessful();
});

it('resolves compound table names to correct model class', function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing.search_path', 'external,public');

    mockTableExists('external', 'authorized_charges', true);
    mockTransactionExecutesCallback();
    mockCreateTempTable();
    mockGetForeignKeys('external', 'authorized_charges', []);
    mockAlterTableSetSchema('external', 'authorized_charges', 'public');
    mockSelectSavedFks([]);

    $modelContent = modelWithoutTable('AuthorizedCharge');

    File::shouldReceive('exists')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/AuthorizedCharge.php'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->withArgs(fn ($path) => str_ends_with($path, 'Models/AuthorizedCharge.php'))
        ->andReturn($modelContent);

    File::shouldReceive('put')
        ->once()
        ->withArgs(fn ($path, $content) => str_ends_with($path, 'Models/AuthorizedCharge.php')
            && str_contains($content, "protected \$table = 'public.authorized_charges';"));

    $this->artisan('schema:move-table', [
        'table' => 'authorized_charges',
        '--from' => 'external',
        '--to' => 'public',
        '--force' => true,
    ])
        ->expectsOutputToContain("Added \$table = 'public.authorized_charges'")
        ->assertSuccessful();
});
