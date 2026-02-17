<?php

namespace Aldoggutierrez\LaravelSchemaManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MoveTableToSchemaCommand extends Command
{
    public $signature = 'schema:move-table
                        {table : The table name to move}
                        {--from= : Source schema (defaults to config)}
                        {--to= : Destination schema (defaults to config)}
                        {--dry-run : Preview changes without executing}
                        {--force : Skip confirmation prompt}';

    public $description = 'Move a table from one schema to another, preserving foreign keys';

    public function handle(): int
    {
        $table = $this->argument('table');
        $schemaFrom = $this->option('from') ?? config('schema-manager.default_source_schema');
        $schemaTo = $this->option('to') ?? config('schema-manager.default_destination_schema');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Verificar que la tabla existe
        if (! $this->tableExists($table, $schemaFrom)) {
            $this->error("❌ Table '$table' does not exist in schema '$schemaFrom'");

            return self::FAILURE;
        }

        // Mostrar resumen
        $this->info("📦 Table: $table");
        $this->info("📤 From: $schemaFrom");
        $this->info("📥 To: $schemaTo");
        $this->newLine();

        // Confirmación
        if (! $force && ! $dryRun) {
            if (! $this->confirm('Do you want to proceed?', true)) {
                $this->warn('Operation cancelled');

                return self::SUCCESS;
            }
            $this->newLine();
        }

        // Crear schema destino si no existe
        if (! $this->schemaExists($schemaTo)) {
            if ($dryRun) {
                $this->warn("  Would create schema '$schemaTo'");
                $this->newLine();
            } else {
                if (! $force && ! $this->confirm("Schema '$schemaTo' does not exist. Do you want to create it?", true)) {
                    $this->warn('Operation cancelled');

                    return self::SUCCESS;
                }

                $this->createSchema($schemaTo);
                $this->info("✓ Created schema '$schemaTo'");
                $this->newLine();
            }
        }

        try {
            $this->moveTable($table, $schemaFrom, $schemaTo, $dryRun);

            if ($dryRun) {
                $this->warn('✓ DRY RUN completed - No changes were made');
            } else {
                $this->info('✅ Table moved successfully!');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('❌ Error: '.$e->getMessage());

            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    protected function tableExists(string $table, string $schema): bool
    {
        $result = DB::selectOne('
            SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = ?
                AND table_name = ?
            ) as exists
        ', [$schema, $table]);

        return $result->exists;
    }

    protected function schemaExists(string $schema): bool
    {
        $result = DB::selectOne('
            SELECT EXISTS (
                SELECT FROM information_schema.schemata
                WHERE schema_name = ?
            ) as exists
        ', [$schema]);

        return $result->exists;
    }

    protected function createSchema(string $schema): void
    {
        DB::statement("CREATE SCHEMA IF NOT EXISTS $schema");
    }

    /**
     * @throws Throwable
     */
    protected function moveTable(string $table, string $schemaFrom, string $schemaTo, bool $dryRun): void
    {
        DB::transaction(function () use ($table, $schemaFrom, $schemaTo, $dryRun) {
            // Crear tabla temporal para guardar FKs
            DB::statement('
                CREATE TEMP TABLE IF NOT EXISTS temp_move_fks (
                    constraint_name TEXT,
                    column_name TEXT,
                    foreign_schema TEXT,
                    foreign_table TEXT,
                    foreign_column TEXT,
                    update_rule TEXT,
                    delete_rule TEXT
                ) ON COMMIT DROP
            ');

            // Obtener FKs
            $fks = $this->getForeignKeys($table, $schemaFrom);

            if (count($fks) > 0) {
                $this->info('🔗 Found '.count($fks).' foreign key(s)');
                $this->newLine();
            }

            // Eliminar FKs
            $this->dropForeignKeys($fks, $table, $schemaFrom, $dryRun);

            // Mover tabla
            if ($dryRun) {
                $this->line("  Would execute: ALTER TABLE $schemaFrom.$table SET SCHEMA $schemaTo");
            } else {
                DB::statement("ALTER TABLE $schemaFrom.$table SET SCHEMA $schemaTo");
                $this->info("✓ Table moved to schema '$schemaTo'");
            }
            $this->newLine();

            // Recrear FKs
            $this->recreateForeignKeys($table, $schemaTo, $dryRun);

            if ($dryRun) {
                DB::rollBack();
            }
        });
    }

    protected function getForeignKeys(string $table, string $schema): array
    {
        return DB::select("
            SELECT
                tc.constraint_name,
                kcu.column_name,
                ccu.table_schema AS foreign_schema,
                ccu.table_name AS foreign_table,
                ccu.column_name AS foreign_column,
                rc.update_rule,
                rc.delete_rule
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
            JOIN information_schema.referential_constraints rc
                ON rc.constraint_name = tc.constraint_name
            WHERE tc.table_schema = ?
              AND tc.table_name = ?
              AND tc.constraint_type = 'FOREIGN KEY'
        ", [$schema, $table]);
    }

    protected function dropForeignKeys(array $fks, string $table, string $schema, bool $dryRun): void
    {
        foreach ($fks as $fk) {
            if ($dryRun) {
                $this->line("  Would drop FK: $fk->constraint_name");
            } else {
                DB::statement("ALTER TABLE $schema.$table DROP CONSTRAINT $fk->constraint_name");
                $this->line("  ✓ Dropped FK: $fk->constraint_name");
            }

            // Guardar para recrear
            DB::insert('INSERT INTO temp_move_fks VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $fk->constraint_name,
                $fk->column_name,
                $fk->foreign_schema,
                $fk->foreign_table,
                $fk->foreign_column,
                $fk->update_rule,
                $fk->delete_rule,
            ]);
        }
    }

    protected function recreateForeignKeys(string $table, string $schema, bool $dryRun): void
    {
        $savedFks = DB::select('SELECT * FROM temp_move_fks');

        foreach ($savedFks as $fk) {
            $fkDef = "ALTER TABLE $schema.{$table}
                ADD CONSTRAINT {$fk->constraint_name}
                FOREIGN KEY ($fk->column_name)
                REFERENCES $fk->foreign_schema.$fk->foreign_table($fk->foreign_column)
                ON UPDATE {$fk->update_rule}
                ON DELETE $fk->delete_rule";

            if ($dryRun) {
                $this->line("  Would recreate FK: $fk->constraint_name → $fk->foreign_schema.$fk->foreign_table");
            } else {
                DB::statement($fkDef);
                $this->line("  ✓ Recreated FK: $fk->constraint_name → $fk->foreign_schema.$fk->foreign_table");
            }
        }
    }
}
