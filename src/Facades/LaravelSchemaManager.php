<?php

namespace Aldoggutierrez\LaravelSchemaManager\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Aldoggutierrez\LaravelSchemaManager\LaravelSchemaManager
 */
class LaravelSchemaManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Aldoggutierrez\LaravelSchemaManager\LaravelSchemaManager::class;
    }
}
