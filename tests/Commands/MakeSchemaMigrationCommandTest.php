<?php

use Illuminate\Filesystem\Filesystem;

it('creates a migration file when --from and --to are provided', function () {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')
        ->once()
        ->withArgs(fn (string $path, string $content) =>
            str_contains($path, 'move_users_from_external_to_public.php') &&
            str_contains($content, 'ALTER TABLE "external"."users" SET SCHEMA "public"')
        );

    $this->app->instance(Filesystem::class, $filesystem);

    $this->artisan('make:schema-migration', [
        'table'  => 'users',
        '--from' => 'external',
        '--to'   => 'public',
    ])
        ->expectsOutputToContain('Migration created')
        ->assertSuccessful();
});

it('fails when source and target schemas are the same', function () {
    $this->artisan('make:schema-migration', [
        'table'  => 'users',
        '--from' => 'public',
        '--to'   => 'public',
    ])
        ->expectsOutputToContain('Source and target schemas must be different')
        ->assertFailed();
});

it('fails when from schema is empty after the prompt', function () {
    $this->artisan('make:schema-migration', ['table' => 'orders'])
        ->expectsQuestion('Source schema?', '')
        ->expectsQuestion('Target schema?', 'public')
        ->expectsOutputToContain('Both --from and --to schemas are required')
        ->assertFailed();
});

it('fails when to schema is empty after the prompt', function () {
    $this->artisan('make:schema-migration', [
        'table'  => 'orders',
        '--from' => 'external',
    ])
        ->expectsQuestion('Target schema?', '')
        ->expectsOutputToContain('Both --from and --to schemas are required')
        ->assertFailed();
});

it('prompts for both schemas when no options are provided', function () {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')->once();
    $this->app->instance(Filesystem::class, $filesystem);

    $this->artisan('make:schema-migration', ['table' => 'orders'])
        ->expectsQuestion('Source schema?', 'external')
        ->expectsQuestion('Target schema?', 'public')
        ->expectsOutputToContain('Migration created')
        ->assertSuccessful();
});

it('includes the table and schema names in the migration filename', function () {
    $capturedPath = '';

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')
        ->once()
        ->withArgs(function (string $path) use (&$capturedPath) {
            $capturedPath = $path;

            return true;
        });

    $this->app->instance(Filesystem::class, $filesystem);

    $this->artisan('make:schema-migration', [
        'table'  => 'products',
        '--from' => 'staging',
        '--to'   => 'production',
    ])->assertSuccessful();

    expect($capturedPath)->toContain('move_products_from_staging_to_production');
});

it('stores the migration file under the database migrations directory', function () {
    $capturedPath = '';

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')
        ->once()
        ->withArgs(function (string $path) use (&$capturedPath) {
            $capturedPath = $path;

            return true;
        });

    $this->app->instance(Filesystem::class, $filesystem);

    $this->artisan('make:schema-migration', [
        'table'  => 'users',
        '--from' => 'external',
        '--to'   => 'public',
    ])->assertSuccessful();

    expect($capturedPath)->toContain('migrations');
});

it('generates correct up() SQL to move the table forward', function () {
    $capturedContent = '';

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')
        ->once()
        ->withArgs(function (string $path, string $content) use (&$capturedContent) {
            $capturedContent = $content;

            return true;
        });

    $this->app->instance(Filesystem::class, $filesystem);

    $this->artisan('make:schema-migration', [
        'table'  => 'orders',
        '--from' => 'external',
        '--to'   => 'public',
    ])->assertSuccessful();

    expect($capturedContent)
        ->toContain('ALTER TABLE "external"."orders" SET SCHEMA "public"');
});

it('generates correct down() SQL to reverse the migration', function () {
    $capturedContent = '';

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')
        ->once()
        ->withArgs(function (string $path, string $content) use (&$capturedContent) {
            $capturedContent = $content;

            return true;
        });

    $this->app->instance(Filesystem::class, $filesystem);

    $this->artisan('make:schema-migration', [
        'table'  => 'orders',
        '--from' => 'external',
        '--to'   => 'public',
    ])->assertSuccessful();

    expect($capturedContent)
        ->toContain('ALTER TABLE "public"."orders" SET SCHEMA "external"');
});

it('generates a stub that handles sequences in both directions', function () {
    $capturedContent = '';

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')
        ->once()
        ->withArgs(function (string $path, string $content) use (&$capturedContent) {
            $capturedContent = $content;

            return true;
        });

    $this->app->instance(Filesystem::class, $filesystem);

    $this->artisan('make:schema-migration', [
        'table'  => 'orders',
        '--from' => 'external',
        '--to'   => 'public',
    ])->assertSuccessful();

    expect($capturedContent)
        ->toContain('getSequences')
        ->toContain('ALTER SEQUENCE "external"')
        ->toContain('ALTER SEQUENCE "public"');
});

it('generates a valid Migration class with up() and down() methods', function () {
    $capturedContent = '';

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')
        ->once()
        ->withArgs(function (string $path, string $content) use (&$capturedContent) {
            $capturedContent = $content;

            return true;
        });

    $this->app->instance(Filesystem::class, $filesystem);

    $this->artisan('make:schema-migration', [
        'table'  => 'users',
        '--from' => 'external',
        '--to'   => 'public',
    ])->assertSuccessful();

    expect($capturedContent)
        ->toContain('extends Migration')
        ->toContain('public function up()')
        ->toContain('public function down()');
});