<?php

use Aldoggutierrez\LaravelSchemaManager\Console\Commands\SchemaDump;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Register a test double for schema:dump that stubs out the pg_dump calls.
 * The captured calls are stored on the instance so tests can inspect them.
 */
beforeEach(function () {
    $this->dumpCommand = new class extends SchemaDump
    {
        public array $dumpedCalls = [];

        protected function dumpSchema(array $connection, string $schemaFlags, string $path, array $env): void
        {
            $this->dumpedCalls[] = [
                'type'        => 'schema',
                'schemaFlags' => $schemaFlags,
                'path'        => $path,
                'env'         => $env,
            ];
        }

        protected function dumpMigrationsData(array $connection, string $schemaFlags, string $path, array $env): void
        {
            $this->dumpedCalls[] = [
                'type'        => 'migrations',
                'schemaFlags' => $schemaFlags,
                'path'        => $path,
            ];
        }
    };

    $this->app->instance(SchemaDump::class, $this->dumpCommand);
});

function setPgsqlConnection(array $overrides = []): void
{
    config()->set('database.connections.pgsql', array_merge([
        'driver'   => 'pgsql',
        'host'     => 'localhost',
        'port'     => 5432,
        'username' => 'testuser',
        'password' => 'testpass',
        'database' => 'testdb',
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('fails when the database connection is not configured', function () {
    $this->artisan('schema:dump', ['--database' => 'nonexistent'])
        ->expectsOutputToContain('Connection [nonexistent] not found')
        ->assertFailed();
});

it('fails when the default pgsql connection is missing from config', function () {
    // Ensure pgsql is not present
    config()->set('database.connections.pgsql', null);

    $this->artisan('schema:dump')
        ->expectsOutputToContain('Connection [pgsql] not found')
        ->assertFailed();
});

// ---------------------------------------------------------------------------
// Success path
// ---------------------------------------------------------------------------

it('calls dumpSchema and dumpMigrationsData for a valid connection', function () {
    setPgsqlConnection();

    $this->artisan('schema:dump')->assertSuccessful();

    expect($this->dumpCommand->dumpedCalls)->toHaveCount(2);
    expect($this->dumpCommand->dumpedCalls[0]['type'])->toBe('schema');
    expect($this->dumpCommand->dumpedCalls[1]['type'])->toBe('migrations');
});

it('outputs a success message containing the schema name and confirmation', function () {
    setPgsqlConnection();

    $this->artisan('schema:dump', ['--schemas' => 'billing'])
        ->expectsOutputToContain('Schema dumped for [billing]')
        ->assertSuccessful();
});

// ---------------------------------------------------------------------------
// Schema resolution
// ---------------------------------------------------------------------------

it('uses the --schemas option to build the schema flags', function () {
    setPgsqlConnection();

    $this->artisan('schema:dump', ['--schemas' => 'billing,reports'])->assertSuccessful();

    expect($this->dumpCommand->dumpedCalls[0]['schemaFlags'])
        ->toContain('--schema=billing')
        ->toContain('--schema=reports');
});

it('uses the connection search_path when --schemas is not provided', function () {
    setPgsqlConnection(['search_path' => 'external,public']);

    $this->artisan('schema:dump')->assertSuccessful();

    expect($this->dumpCommand->dumpedCalls[0]['schemaFlags'])
        ->toContain('--schema=external')
        ->toContain('--schema=public');
});

it('falls back to public when neither --schemas nor search_path is set', function () {
    setPgsqlConnection(); // no search_path key

    $this->artisan('schema:dump')->assertSuccessful();

    expect($this->dumpCommand->dumpedCalls[0]['schemaFlags'])
        ->toContain('--schema=public');
});

it('trims whitespace around schema names in the --schemas option', function () {
    setPgsqlConnection();

    $this->artisan('schema:dump', ['--schemas' => ' billing , reports '])->assertSuccessful();

    expect($this->dumpCommand->dumpedCalls[0]['schemaFlags'])
        ->toContain('--schema=billing')
        ->toContain('--schema=reports');
});

// ---------------------------------------------------------------------------
// Path resolution
// ---------------------------------------------------------------------------

it('uses a custom --path when provided', function () {
    setPgsqlConnection();

    $this->artisan('schema:dump', ['--path' => '/tmp/my-schema.sql'])->assertSuccessful();

    expect($this->dumpCommand->dumpedCalls[0]['path'])->toBe('/tmp/my-schema.sql');
});

it('defaults the path to database/schema/{connection}-schema.sql', function () {
    setPgsqlConnection();

    $this->artisan('schema:dump')->assertSuccessful();

    expect($this->dumpCommand->dumpedCalls[0]['path'])
        ->toContain('pgsql-schema.sql');
});

it('passes the database password in the env array for pg_dump', function () {
    setPgsqlConnection(['password' => 'supersecret']);

    $this->artisan('schema:dump')->assertSuccessful();

    expect($this->dumpCommand->dumpedCalls[0]['env'])
        ->toBe(['PGPASSWORD' => 'supersecret']);
});

// ---------------------------------------------------------------------------
// Prune
// ---------------------------------------------------------------------------

it('prunes migration files and shows confirmation when --prune is passed', function () {
    setPgsqlConnection();

    File::shouldReceive('files')
        ->once()
        ->andReturn(['/db/migrations/2024_01_01_create_foo.php']);

    File::shouldReceive('delete')
        ->once()
        ->with('/db/migrations/2024_01_01_create_foo.php');

    $this->artisan('schema:dump', ['--prune' => true])
        ->expectsOutputToContain('Migration files pruned')
        ->assertSuccessful();
});

it('does not prune migration files when --prune is not passed', function () {
    setPgsqlConnection();

    $this->artisan('schema:dump')
        ->doesntExpectOutputToContain('Migration files pruned')
        ->assertSuccessful();
});

it('uses a custom --database connection', function () {
    config()->set('database.connections.myconn', [
        'driver'   => 'pgsql',
        'host'     => 'db.example.com',
        'port'     => 5432,
        'username' => 'myuser',
        'password' => 'mypass',
        'database' => 'mydb',
    ]);

    $this->artisan('schema:dump', ['--database' => 'myconn'])->assertSuccessful();

    expect($this->dumpCommand->dumpedCalls[0]['path'])
        ->toContain('myconn-schema.sql');
});