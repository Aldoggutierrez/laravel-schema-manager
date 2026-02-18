<?php

namespace Aldoggutierrez\LaravelSchemaManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class SchemaDump extends Command
{
    protected $signature = 'schema:dump
                            {--database=pgsql : Database connection to use}
                            {--schemas= : Comma-separated schemas to dump (default: connection search_path)}
                            {--path= : Path to dump file (default: database/schema/{connection}-schema.sql)}
                            {--prune : Delete existing migration files after dump}';

    protected $description = 'Dump the database schema for the given schemas (defaults to search_path)';

    public function handle(): int
    {
        $connection = config('database.connections.' . $this->option('database'));

        if (!$connection) {
            $this->error('Connection [' . $this->option('database') . '] not found.');

            return self::FAILURE;
        }

        $path = $this->option('path') ?? database_path('schema/' . $this->option('database') . '-schema.sql');

        $schemasSource = $this->option('schemas') ?? $connection['search_path'] ?? 'public';

        $schemas = collect(explode(',', $schemasSource))
            ->map(fn($s) => trim($s))
            ->filter();

        $schemaFlags = $schemas->map(fn($s) => "--schema=$s")->implode(' ');

        $env = ['PGPASSWORD' => $connection['password']];

        $this->dumpSchema($connection, $schemaFlags, $path, $env);
        $this->dumpMigrationsData($connection, $schemaFlags, $path, $env);

        if ($this->option('prune')) {
            $this->pruneMigrations();
        }

        $schemas = $schemas->implode(', ');
        $this->components->info("Schema dumped for [$schemas] to: <fg=green>$path</>");

        return self::SUCCESS;
    }

    protected function dumpSchema(array $connection, string $schemaFlags, string $path, array $env): void
    {
        $command = sprintf(
            'pg_dump --no-owner --no-acl %s --schema-only -h %s -p %s -U %s %s > %s',
            $schemaFlags,
            $connection['host'],
            $connection['port'] ?? 5432,
            $connection['username'],
            $connection['database'],
            $path,
        );

        Process::fromShellCommandline($command)
            ->setTimeout(null)
            ->mustRun(null, $env);
    }

    protected function dumpMigrationsData(array $connection, string $schemaFlags, string $path, array $env): void
    {
        $migrationsTable = config('database.migrations', 'migrations');

        $schema = $this->resolveMigrationSchema($migrationsTable, $connection);

        if (!$schema) {
            return;
        }

        $command = sprintf(
            'pg_dump --no-owner --no-acl %s -t "%s"."%s" --data-only -h %s -p %s -U %s %s >> %s',
            $schemaFlags,
            $schema,
            $migrationsTable,
            $connection['host'],
            $connection['port'] ?? 5432,
            $connection['username'],
            $connection['database'],
            $path,
        );

        Process::fromShellCommandline($command)
            ->setTimeout(null)
            ->mustRun(null, $env);
    }

    private function resolveMigrationSchema(string $table, array $connection): ?string
    {
        $schemasSource = $this->option('schemas') ?? $connection['search_path'] ?? 'public';

        $schemas = collect(explode(',', $schemasSource))
            ->map(fn($s) => trim($s))
            ->filter()
            ->all();

        $placeholders = implode(',', array_fill(0, count($schemas), '?'));

        $result = DB::connection($this->option('database'))->selectOne(
            "SELECT schemaname FROM pg_tables WHERE tablename = ? AND schemaname IN ($placeholders) LIMIT 1",
            [$table, ...$schemas]
        );

        return $result?->schemaname;
    }

    private function pruneMigrations(): void
    {
        collect(File::files(database_path('migrations')))
            ->each(fn($file) => File::delete($file));

        $this->components->info('Migration files pruned.');
    }
}

