<?php

namespace App\Services\Mssql;

use InvalidArgumentException;

class LegacyMirrorSourceService
{
    use InteractsWithMssql;

    /**
     * @return array<int, array{name: string, schema: string, rows: int|null}>
     */
    public function tables(?string $like = null): array
    {
        $params = [];
        $where = "WHERE TABLE_TYPE = 'BASE TABLE'";

        if ($like !== null && $like !== '') {
            $where .= ' AND TABLE_NAME LIKE ?';
            $params[] = $like;
        }

        $rows = $this->fetchAll(
            "SELECT TABLE_SCHEMA, TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             {$where}
             ORDER BY TABLE_SCHEMA, TABLE_NAME",
            $params,
        );

        return array_map(fn (array $row): array => [
            'schema' => (string) $row['TABLE_SCHEMA'],
            'name' => (string) $row['TABLE_NAME'],
            'rows' => null,
        ], $rows);
    }

    /**
     * @return array<int, array{name: string, ordinal: int}>
     */
    public function columns(string $schema, string $table): array
    {
        $rows = $this->fetchAll(
            'SELECT COLUMN_NAME, ORDINAL_POSITION
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$schema, $table],
        );

        return array_map(fn (array $row): array => [
            'name' => (string) $row['COLUMN_NAME'],
            'ordinal' => (int) $row['ORDINAL_POSITION'],
        ], $rows);
    }

    public function countRows(string $schema, string $table): int
    {
        $row = $this->fetchAll(sprintf(
            'SELECT COUNT_BIG(*) AS row_count FROM %s.%s',
            $this->quoteSqlServerIdentifier($schema),
            $this->quoteSqlServerIdentifier($table),
        ))[0] ?? ['row_count' => 0];

        return (int) $row['row_count'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->fetchAll($sql, $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $schema, string $table, int $offset, int $limit): array
    {
        if ($offset < 0 || $limit < 1) {
            throw new InvalidArgumentException('Invalid paging values.');
        }

        $sql = sprintf(
            'SELECT * FROM %s.%s ORDER BY (SELECT 1) OFFSET %d ROWS FETCH NEXT %d ROWS ONLY',
            $this->quoteSqlServerIdentifier($schema),
            $this->quoteSqlServerIdentifier($table),
            $offset,
            $limit,
        );

        return $this->fetchAll($sql);
    }

    private function quoteSqlServerIdentifier(string $identifier): string
    {
        return '['.str_replace(']', ']]', $identifier).']';
    }
}
