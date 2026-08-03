<?php

// Read-only connection info for legacy master-data verification tools.
// Historical POS sales are intentionally not imported into this ERP.

return [
    'host' => env('MSSQL_SOURCE_HOST'),
    'port' => env('MSSQL_SOURCE_PORT'),
    'database' => env('MSSQL_SOURCE_DATABASE'),
    'username' => env('MSSQL_SOURCE_USERNAME'),
    'password' => env('MSSQL_SOURCE_PASSWORD'),
    'driver' => env('MSSQL_SOURCE_DRIVER', 'SQL Server'),
    'tds_version' => env('MSSQL_SOURCE_TDS_VERSION', '7.4'),
    'trusted' => env('MSSQL_SOURCE_TRUSTED', false),
];
