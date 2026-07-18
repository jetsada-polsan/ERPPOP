<?php

namespace App\Services\Mssql;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Shared raw-PDO (odbc driver) connection to the legacy BPlus MSSQL server, plus the
 * Thai-codepage decoding every consumer needs. See MssqlPosSourceService for why this
 * isn't a Laravel DB connection (no native sqlsrv extension installed).
 */
trait InteractsWithMssql
{
    private ?PDO $mssqlPdo = null;

    protected function connection(): PDO
    {
        if ($this->mssqlPdo !== null) {
            return $this->mssqlPdo;
        }

        $host = config('mssql_source.host');
        $database = config('mssql_source.database');
        $username = config('mssql_source.username');
        $password = config('mssql_source.password');
        $driver = config('mssql_source.driver', 'SQL Server');
        $trusted = filter_var(config('mssql_source.trusted'), FILTER_VALIDATE_BOOL);

        $dsn = "odbc:Driver={".$driver."};Server={$host};Database={$database};";
        if ($trusted) {
            $dsn .= 'Trusted_Connection=Yes;';
            $username = null;
            $password = null;
        }

        try {
            $this->mssqlPdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Unable to connect to BPlus MSSQL source: '.$e->getMessage(), previous: $e);
        }

        return $this->mssqlPdo;
    }

    /**
     * This legacy MSSQL schema stores Thai text in plain VARCHAR columns using the
     * Windows-874 (Thai) codepage, not Unicode. The "SQL Server" ODBC driver passes
     * those bytes through unconverted, so every string value coming back from a
     * query must be re-encoded to UTF-8 before it touches anything else (Eloquent,
     * JSON columns, PostgreSQL - all of which require valid UTF-8).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function decodeRows(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach ($row as $key => $value) {
                if (is_string($value)) {
                    $row[$key] = $this->decodeThaiString($value);
                }
            }
        }

        return $rows;
    }

    protected function decodeThaiString(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @iconv('CP874', 'UTF-8//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);

        return $this->decodeRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
