<?php

use Illuminate\Support\Facades\DB;

it('lists tables in the default schema when no arguments are given', function () {
    config()->set('schema-manager.default_source_schema', 'external');

    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'information_schema.tables') && $bindings === ['external'])
        ->andReturn([
            (object) ['table_name' => 'users', 'size' => '16 kB'],
            (object) ['table_name' => 'orders', 'size' => '8 kB'],
        ]);

    $this->artisan('schema:list-tables')
        ->expectsOutputToContain('external')
        ->expectsTable(['Table', 'Size'], [
            ['Table' => 'users', 'Size' => '16 kB'],
            ['Table' => 'orders', 'Size' => '8 kB'],
        ])
        ->assertSuccessful();
});

it('lists tables in a specific schema provided as argument', function () {
    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'information_schema.tables') && $bindings === ['my_schema'])
        ->andReturn([
            (object) ['table_name' => 'products', 'size' => '32 kB'],
        ]);

    $this->artisan('schema:list-tables', ['schema' => 'my_schema'])
        ->expectsOutputToContain('my_schema')
        ->expectsTable(['Table', 'Size'], [
            ['Table' => 'products', 'Size' => '32 kB'],
        ])
        ->assertSuccessful();
});

it('lists tables from all schemas when --all flag is used', function () {
    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query) => str_contains($query, 'information_schema.schemata'))
        ->andReturn([
            (object) ['schema_name' => 'public'],
            (object) ['schema_name' => 'external'],
        ]);

    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'information_schema.tables') && $bindings === ['public'])
        ->andReturn([
            (object) ['table_name' => 'users', 'size' => '16 kB'],
        ]);

    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'information_schema.tables') && $bindings === ['external'])
        ->andReturn([
            (object) ['table_name' => 'imports', 'size' => '64 kB'],
        ]);

    $this->artisan('schema:list-tables', ['--all' => true])
        ->expectsOutputToContain('Available Schemas')
        ->expectsOutputToContain('public')
        ->expectsTable(['Table', 'Size'], [
            ['Table' => 'users', 'Size' => '16 kB'],
        ])
        ->expectsOutputToContain('external')
        ->expectsTable(['Table', 'Size'], [
            ['Table' => 'imports', 'Size' => '64 kB'],
        ])
        ->assertSuccessful();
});

it('shows warning when schema has no tables', function () {
    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'information_schema.tables') && $bindings === ['empty_schema'])
        ->andReturn([]);

    $this->artisan('schema:list-tables', ['schema' => 'empty_schema'])
        ->expectsOutputToContain('has no tables')
        ->assertSuccessful();
});

it('uses custom config value as default schema', function () {
    config()->set('schema-manager.default_source_schema', 'custom_schema');

    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'information_schema.tables') && $bindings === ['custom_schema'])
        ->andReturn([
            (object) ['table_name' => 'items', 'size' => '8 kB'],
        ]);

    $this->artisan('schema:list-tables')
        ->expectsOutputToContain('custom_schema')
        ->expectsTable(['Table', 'Size'], [
            ['Table' => 'items', 'Size' => '8 kB'],
        ])
        ->assertSuccessful();
});

it('displays the table count in the schema header', function () {
    DB::shouldReceive('select')
        ->once()
        ->withArgs(fn ($query, $bindings) => str_contains($query, 'information_schema.tables'))
        ->andReturn([
            (object) ['table_name' => 'a', 'size' => '8 kB'],
            (object) ['table_name' => 'b', 'size' => '8 kB'],
            (object) ['table_name' => 'c', 'size' => '8 kB'],
        ]);

    $this->artisan('schema:list-tables', ['schema' => 'test'])
        ->expectsOutputToContain('3 tables')
        ->expectsTable(['Table', 'Size'], [
            ['Table' => 'a', 'Size' => '8 kB'],
            ['Table' => 'b', 'Size' => '8 kB'],
            ['Table' => 'c', 'Size' => '8 kB'],
        ])
        ->assertSuccessful();
});
