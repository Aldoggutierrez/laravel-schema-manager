<?php

namespace Aldoggutierrez\LaravelSchemaManager;

use Aldoggutierrez\LaravelSchemaManager\Console\Commands\ListTablesCommand;
use Aldoggutierrez\LaravelSchemaManager\Console\Commands\MakeSchemaMigration;
use Aldoggutierrez\LaravelSchemaManager\Console\Commands\MoveTableToSchemaCommand;
use Aldoggutierrez\LaravelSchemaManager\Console\Commands\SchemaDump;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelSchemaManagerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-schema-manager')
            ->hasConfigFile()
            ->hasCommands([
                MoveTableToSchemaCommand::class,
                ListTablesCommand::class,
                MakeSchemaMigration::class,
                SchemaDump::class,
            ]);
    }
}
