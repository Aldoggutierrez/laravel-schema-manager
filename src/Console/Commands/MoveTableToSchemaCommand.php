<?php

namespace Aldoggutierrez\LaravelSchemaManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class MoveTableToSchemaCommand extends Command
{
    public $signature = 'schema:move-table
                        {table : The table name to move}
                        {--from= : Source schema (defaults to config)}
                        {--to= : Destination schema (defaults to config)}
                        {--model= : Eloquent model class to update (e.g. App\\Models\\User)}
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
            $this->updateModelTableProperty($table, $schemaTo, $dryRun);

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
            $this->recreateForeignKeys($table, $schemaFrom, $schemaTo, $dryRun);

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

    protected function recreateForeignKeys(string $table, string $schemaFrom, string $schema, bool $dryRun): void
    {
        $savedFks = DB::select('SELECT * FROM temp_move_fks');

        foreach ($savedFks as $fk) {
            // Self-referential FK: the table now lives in $schema, not $schemaFrom
            $foreignSchema = ($fk->foreign_table === $table && $fk->foreign_schema === $schemaFrom)
                ? $schema
                : $fk->foreign_schema;

            $fkDef = "ALTER TABLE $schema.{$table}
                ADD CONSTRAINT {$fk->constraint_name}
                FOREIGN KEY ($fk->column_name)
                REFERENCES $foreignSchema.$fk->foreign_table($fk->foreign_column)
                ON UPDATE {$fk->update_rule}
                ON DELETE $fk->delete_rule";

            if ($dryRun) {
                $this->line("  Would recreate FK: $fk->constraint_name → $foreignSchema.$fk->foreign_table");
            } else {
                DB::statement($fkDef);
                $this->line("  ✓ Recreated FK: $fk->constraint_name → $foreignSchema.$fk->foreign_table");
            }
        }
    }

    protected function getDefaultSchema(): ?string
    {
        $connection = config('schema-manager.connection') ?? config('database.default');
        $searchPath = config("database.connections.{$connection}.search_path");

        if ($searchPath === null) {
            return null;
        }

        if (is_string($searchPath)) {
            $schemas = array_map('trim', explode(',', $searchPath));
        } elseif (is_array($searchPath)) {
            $schemas = $searchPath;
        } else {
            return null;
        }

        $schemas = array_map(fn (string $s) => trim($s, " \t\n\r\0\x0B\"'"), $schemas);
        $schemas = array_values(array_filter($schemas));

        return $schemas[0] ?? null;
    }

    protected function resolveModelPath(string $table): ?string
    {
        $modelOption = $this->option('model');

        if ($modelOption !== null) {
            $relativePath = str_replace('\\', '/', $modelOption).'.php';

            if (str_starts_with($relativePath, 'App/')) {
                $relativePath = substr($relativePath, 4);
            }

            $fullPath = app_path($relativePath);

            if (! File::exists($fullPath)) {
                $this->warn("⚠ Model file not found: {$fullPath}");

                return null;
            }

            return $fullPath;
        }

        $className = Str::studly(Str::singular($table));
        $fullPath = app_path("Models/{$className}.php");

        if (! File::exists($fullPath)) {
            $this->warn("⚠ Model file not found: {$fullPath} (use --model to specify)");

            return null;
        }

        return $fullPath;
    }

    protected function updateModelTableProperty(string $table, string $schemaTo, bool $dryRun): void
    {
        try {
            $modelPath = $this->resolveModelPath($table);

            if ($modelPath === null) {
                return;
            }

            $defaultSchema = $this->getDefaultSchema();
            $isMovingToDefault = $defaultSchema !== null && $schemaTo === $defaultSchema;

            if ($isMovingToDefault) {
                if ($dryRun) {
                    $this->line("  Would remove \$table property from model: {$modelPath}");
                } else {
                    $this->removeTableProperty($modelPath);
                }
            } else {
                $newTableValue = "{$schemaTo}.{$table}";
                if ($dryRun) {
                    $this->line("  Would set \$table = '{$newTableValue}' in model: {$modelPath}");
                } else {
                    $this->setTableProperty($modelPath, $newTableValue);
                }
            }
        } catch (Throwable $e) {
            $this->warn("⚠ Could not update model file: {$e->getMessage()}");
        }
    }

    protected function removeTableProperty(string $modelPath): void
    {
        $contents = File::get($modelPath);

        $pattern = '/\n\s*protected\s+\$table\s*=\s*[\'"][^\'"]*[\'"]\s*;\s*\n/';

        $newContents = preg_replace($pattern, "\n", $contents, 1, $count);

        if ($count > 0) {
            File::put($modelPath, $newContents);
            $this->info('  ✓ Removed $table property from model');
        } else {
            $this->line('  ℹ No $table property found in model (already using convention)');
        }
    }

    protected function setTableProperty(string $modelPath, string $tableValue): void
    {
        $contents = File::get($modelPath);

        $pattern = '/(protected\s+\$table\s*=\s*)[\'"][^\'"]*[\'"](\s*;)/';
        $replacement = "\${1}'{$tableValue}'\${2}";

        $newContents = preg_replace($pattern, $replacement, $contents, 1, $count);

        if ($count > 0) {
            File::put($modelPath, $newContents);
            $this->info("  ✓ Updated \$table = '{$tableValue}' in model");

            return;
        }

        $newContents = $this->insertTableProperty($contents, $tableValue);

        if ($newContents !== null) {
            File::put($modelPath, $newContents);
            $this->info("  ✓ Added \$table = '{$tableValue}' to model");
        } else {
            $this->warn('  ⚠ Could not determine where to insert $table property in model');
        }
    }

    protected function insertTableProperty(string $contents, string $tableValue): ?string
    {
        // Strategy 1: Insert after last indented "use Trait;" line in class body
        preg_match_all('/^([ \t]+use\s+[\w\\\\]+\s*;\s*\n)/m', $contents, $allUseMatches, PREG_OFFSET_CAPTURE);

        if (! empty($allUseMatches[0])) {
            $lastUse = end($allUseMatches[0]);
            $insertPos = $lastUse[1] + strlen($lastUse[0]);

            return substr($contents, 0, $insertPos)
                ."\n    protected \$table = '{$tableValue}';\n"
                .substr($contents, $insertPos);
        }

        // Strategy 2: Insert after class opening brace
        if (preg_match('/^(\s*class\s+\w+[^{]*\{[ \t]*\n)/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $insertPos = $matches[0][1] + strlen($matches[0][0]);

            return substr($contents, 0, $insertPos)
                ."    protected \$table = '{$tableValue}';\n\n"
                .substr($contents, $insertPos);
        }

        return null;
    }
}
