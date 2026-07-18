<?php

// Read-only connection info for the legacy BPlus MSSQL server. Used only by
// App\Services\PosImport\MssqlPosSourceService via raw PDO (odbc driver) -
// not a Laravel database connection, since no native sqlsrv extension is installed.

return [
    'host' => env('MSSQL_SOURCE_HOST'),
    'database' => env('MSSQL_SOURCE_DATABASE'),
    'username' => env('MSSQL_SOURCE_USERNAME'),
    'password' => env('MSSQL_SOURCE_PASSWORD'),
    'driver' => env('MSSQL_SOURCE_DRIVER', 'SQL Server'),
    'trusted' => env('MSSQL_SOURCE_TRUSTED', false),
];
