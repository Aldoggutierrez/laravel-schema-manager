<?php

return [
    /*
     * Default source schema when moving tables
     */
    'default_source_schema' => env('SCHEMA_MANAGER_SOURCE', 'external'),

    /*
     * Default destination schema when moving tables
     */
    'default_destination_schema' => env('SCHEMA_MANAGER_DESTINATION', 'public'),

    /*
     * Database connection to use (leave null to use default)
     */
    'connection' => env('SCHEMA_MANAGER_CONNECTION', null),

    /*
     * Enable query logging during operations
     */
    'log_queries' => env('SCHEMA_MANAGER_LOG_QUERIES', false),
];
