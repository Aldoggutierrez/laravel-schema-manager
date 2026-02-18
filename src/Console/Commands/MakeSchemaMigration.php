<?php

namespace Aldoggutierrez\LaravelSchemaManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeSchemaMigration extends Command
{
    protected $signature = 'make:schema-migration
                            {table : Table name to move}
                            {--from= : Source schema}
                            {--to= : Target schema}';

    protected $description = 'Create a migration to move a table between schemas';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $table = $this->argument('table');
        $from = $this->option('from') ?? $this->ask('Source schema?');
        $to = $this->option('to') ?? $this->ask('Target schema?');

        if (!$from || !$to) {
            $this->error('Both --from and --to schemas are required.');

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->error('Source and target schemas must be different.');

            return self::FAILURE;
        }

        $path = $this->generateMigration($table, $from, $to);

        $this->components->info("Migration created: <fg=green>$path</>");

        return self::SUCCESS;
    }

    private function generateMigration(string $table, string $from, string $to): string
    {
        $timestamp = date('Y_m_d_His');
        $name = "move_{$table}_from_{$from}_to_$to";
        $className = Str::studly($name);
        $filename = "{$timestamp}_$name.php";
        $path = database_path("migrations/$filename");

        $stub = $this->buildStub($table, $from, $to, $className);

        $this->files->put($path, $stub);

        return $path;
    }

    private function buildStub(string $table, string $from, string $to, string $className): string
    {
        return <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\DB;

        return new class extends Migration
        {
            public function up(): void
            {
                DB::statement('ALTER TABLE "$from"."$table" SET SCHEMA "$to"');

                foreach (\$this->getSequences('$from', '$table') as \$sequence) {
                    DB::statement('ALTER SEQUENCE "$from"."'.\$sequence.'" SET SCHEMA "$to"');
                }
            }

            public function down(): void
            {
                DB::statement('ALTER TABLE "$to"."$table" SET SCHEMA "$from"');

                foreach (\$this->getSequences('$to', '$table') as \$sequence) {
                    DB::statement('ALTER SEQUENCE "$to"."'.\$sequence.'" SET SCHEMA "$from"');
                }
            }

            private function getSequences(string \$schema, string \$table): array
            {
                return DB::select(
                    'SELECT s.relname AS name
                     FROM pg_class s
                     JOIN pg_depend d ON d.objid = s.oid
                     JOIN pg_class t ON d.refobjid = t.oid
                     JOIN pg_namespace n ON t.relnamespace = n.oid
                     WHERE s.relkind = \'S\'
                       AND n.nspname = ?
                       AND t.relname = ?',
                    [\$schema, \$table]
                );
            }
        };
        PHP;
    }
}

