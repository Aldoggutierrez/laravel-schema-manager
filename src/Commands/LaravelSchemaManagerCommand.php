<?php

namespace Aldoggutierrez\LaravelSchemaManager\Commands;

use Illuminate\Console\Command;

class LaravelSchemaManagerCommand extends Command
{
    public $signature = 'laravel-schema-manager';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
