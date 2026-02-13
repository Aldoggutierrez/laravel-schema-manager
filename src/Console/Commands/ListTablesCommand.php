<?php

namespace Aldoggutierrez\LaravelSchemaManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListTablesCommand extends Command
{
    public $signature = 'schema:list-tables
                        {schema? : Schema name to list tables from}
                        {--all : List tables from all schemas}';

    public $description = 'List all tables in a schema or all schemas';

    public function handle(): int
    {
        $schema = $this->argument('schema');
        $all = $this->option('all');

        if ($all) {
            $this->listAllSchemas();
        } elseif ($schema) {
            $this->listTablesInSchema($schema);
        } else {
            $defaultSchema = config('schema-manager.default_source_schema');
            $this->listTablesInSchema($defaultSchema);
        }

        return self::SUCCESS;
    }

    protected function listAllSchemas(): void
    {
        $schemas = DB::select("
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name NOT IN ('information_schema', 'pg_catalog', 'pg_toast')
            ORDER BY schema_name
        ");

        $this->info('📂 Available Schemas:');
        $this->newLine();

        foreach ($schemas as $schema) {
            $this->listTablesInSchema($schema->schema_name);
            $this->newLine();
        }
    }

    protected function listTablesInSchema(string $schema): void
    {
        $tables = DB::select("
            SELECT
                table_name,
                pg_size_pretty(pg_total_relation_size(quote_ident(table_schema) || '.' || quote_ident(table_name))) as size
            FROM information_schema.tables
            WHERE table_schema = ?
            AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ", [$schema]);

        if (empty($tables)) {
            $this->warn("Schema '$schema' has no tables");

            return;
        }

        $this->info("📋 Schema: $schema (" . count($tables) . " tables)");

        $tableData = collect($tables)->map(fn ($t) => [
            'Table' => $t->table_name,
            'Size' => $t->size,
        ])->toArray();

        $this->table(['Table', 'Size'], $tableData);
    }
}
