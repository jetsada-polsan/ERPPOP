<?php

namespace App\Console\Commands;

use App\Services\Mssql\LegacyMirrorSourceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MssqlMirrorLegacyBookings extends Command
{
    protected $signature = 'mssql:mirror-legacy-bookings
        {--month=202606 : Legacy booking month in YYYYMM format.}
        {--schema=legacy : PostgreSQL schema to write to.}
        {--chunk=500 : Rows per insert chunk.}
        {--all-documents : Mirror every legacy document type for the month, not only bookings.}';

    protected $description = 'Mirror only legacy BPlus booking documents for one month into PostgreSQL legacy tables.';

    public function handle(LegacyMirrorSourceService $source): int
    {
        $month = (string) $this->option('month');
        if (! preg_match('/^\d{6}$/', $month)) {
            $this->error('--month must be YYYYMM.');

            return self::FAILURE;
        }

        $schema = (string) $this->option('schema');
        $chunk = max(1, (int) $this->option('chunk'));
        $from = substr($month, 0, 4).'-'.substr($month, 4, 2).'-01';
        $to = date('Y-m-d', strtotime($from.' +1 month'));
        $documentWhere = (bool) $this->option('all-documents') ? '1 = 1' : $this->bookingWhere();

        DB::statement('CREATE SCHEMA IF NOT EXISTS '.$this->quotePgIdentifier($schema));

        $jobs = [
            'DOCTYPE' => [
                'sql' => "SELECT DISTINCT dt.* FROM dbo.DOCTYPE dt
                    JOIN dbo.DOCINFO di ON di.DI_DT = dt.DT_KEY
                    WHERE {$documentWhere} AND di.DI_DATE >= ? AND di.DI_DATE < ?",
                'key' => 'DT_KEY',
                'params' => [$from, $to],
            ],
            'DOCINFO' => [
                'sql' => "SELECT di.* FROM dbo.DOCINFO di
                    JOIN dbo.DOCTYPE dt ON dt.DT_KEY = di.DI_DT
                    WHERE {$documentWhere} AND di.DI_DATE >= ? AND di.DI_DATE < ?",
                'key' => 'DI_KEY',
                'params' => [$from, $to],
            ],
            'TRANSTKH' => [
                'sql' => "SELECT trh.* FROM dbo.TRANSTKH trh
                    JOIN dbo.DOCINFO di ON di.DI_KEY = trh.TRH_DI
                    JOIN dbo.DOCTYPE dt ON dt.DT_KEY = di.DI_DT
                    WHERE {$documentWhere} AND di.DI_DATE >= ? AND di.DI_DATE < ?",
                'key' => 'TRH_KEY',
                'params' => [$from, $to],
            ],
            'TRANSTKD' => [
                'sql' => "SELECT trd.* FROM dbo.TRANSTKD trd
                    JOIN dbo.TRANSTKH trh ON trh.TRH_KEY = trd.TRD_TRH
                    JOIN dbo.DOCINFO di ON di.DI_KEY = trh.TRH_DI
                    JOIN dbo.DOCTYPE dt ON dt.DT_KEY = di.DI_DT
                    WHERE {$documentWhere} AND di.DI_DATE >= ? AND di.DI_DATE < ?",
                'key' => 'TRD_KEY',
                'params' => [$from, $to],
            ],
            'AROE' => [
                'sql' => "SELECT aroe.* FROM dbo.AROE aroe
                    JOIN dbo.DOCINFO di ON di.DI_KEY = aroe.AROE_DI
                    JOIN dbo.DOCTYPE dt ON dt.DT_KEY = di.DI_DT
                    WHERE {$documentWhere} AND di.DI_DATE >= ? AND di.DI_DATE < ?",
                'key' => 'AROE_KEY',
                'params' => [$from, $to],
            ],
            'ARFILE' => [
                'sql' => "SELECT ar.* FROM dbo.ARFILE ar
                    WHERE ar.AR_KEY IN (
                        SELECT DISTINCT aroe.AROE_AR
                        FROM dbo.AROE aroe
                        JOIN dbo.DOCINFO di ON di.DI_KEY = aroe.AROE_DI
                        JOIN dbo.DOCTYPE dt ON dt.DT_KEY = di.DI_DT
                        WHERE {$documentWhere} AND di.DI_DATE >= ? AND di.DI_DATE < ?
                    )",
                'key' => 'AR_KEY',
                'params' => [$from, $to],
            ],
            'SKUMASTER' => [
                'sql' => "SELECT sm.* FROM dbo.SKUMASTER sm
                    WHERE sm.SKU_KEY IN (
                        SELECT DISTINCT trd.TRD_SKU
                        FROM dbo.TRANSTKD trd
                        JOIN dbo.TRANSTKH trh ON trh.TRH_KEY = trd.TRD_TRH
                        JOIN dbo.DOCINFO di ON di.DI_KEY = trh.TRH_DI
                        JOIN dbo.DOCTYPE dt ON dt.DT_KEY = di.DI_DT
                        WHERE {$documentWhere} AND di.DI_DATE >= ? AND di.DI_DATE < ?
                    )",
                'key' => 'SKU_KEY',
                'params' => [$from, $to],
            ],
        ];

        foreach ($jobs as $table => $job) {
            $columns = $source->columns('dbo', $table);
            $target = strtolower('dbo__'.$table);
            $this->createTargetTable($schema, $target, $columns);

            $countRow = $source->query('SELECT COUNT_BIG(*) AS row_count FROM ('.$job['sql'].') src', $job['params'])[0] ?? ['row_count' => 0];
            $rowCount = (int) $countRow['row_count'];
            $copied = 0;

            $lastKey = 0;
            while ($copied < $rowCount) {
                $rows = $source->query(
                    'SELECT TOP '.$chunk.' * FROM ('.$job['sql'].') src WHERE src.'.$job['key'].' > ? ORDER BY src.'.$job['key'],
                    array_merge($job['params'], [$lastKey]),
                );
                if ($rows === []) {
                    break;
                }

                $this->insertRows($schema, $target, $columns, $rows);
                $copied += count($rows);
                $lastRow = end($rows);
                $lastKey = (int) ($lastRow[$job['key']] ?? $lastKey);
            }

            $this->line(sprintf('%-18s %8d row(s)', $target, $copied));
        }

        $this->createIndexes($schema);
        $this->line('legacy indexes ready');

        return self::SUCCESS;
    }

    private function bookingWhere(): string
    {
        return "(dt.DT_DOCCODE = 'BK'
            OR dt.DT_DOCCODE = 'BS'
            OR dt.DT_DOCCODE LIKE 'BK[0-9]%'
            OR dt.DT_DOCCODE LIKE 'B[0-9][0-9]')";
    }

    /**
     * @param  array<int, array{name: string, ordinal: int}>  $columns
     */
    private function createTargetTable(string $schema, string $table, array $columns): void
    {
        $columnSql = array_map(
            fn (array $column): string => $this->quotePgIdentifier($column['name']).' text',
            $columns,
        );
        $columnSql[] = '_legacy_synced_at timestamp without time zone not null';

        DB::statement(sprintf(
            'DROP TABLE IF EXISTS %s.%s',
            $this->quotePgIdentifier($schema),
            $this->quotePgIdentifier($table),
        ));
        DB::statement(sprintf(
            'CREATE TABLE %s.%s (%s)',
            $this->quotePgIdentifier($schema),
            $this->quotePgIdentifier($table),
            implode(', ', $columnSql),
        ));
    }

    /**
     * @param  array<int, array{name: string, ordinal: int}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertRows(string $schema, string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columnNames = array_map(fn (array $column): string => $column['name'], $columns);
        $insertColumns = array_merge($columnNames, ['_legacy_synced_at']);
        $maxRowsPerInsert = max(1, intdiv(60000, count($insertColumns)));
        $syncedAt = now()->format('Y-m-d H:i:s');

        foreach (array_chunk($rows, $maxRowsPerInsert) as $rowChunk) {
            $placeholders = [];
            $bindings = [];

            foreach ($rowChunk as $row) {
                $placeholders[] = '('.implode(', ', array_fill(0, count($insertColumns), '?')).')';
                foreach ($columnNames as $columnName) {
                    $value = $row[$columnName] ?? null;
                    $bindings[] = $value === null ? null : (string) $value;
                }
                $bindings[] = $syncedAt;
            }

            DB::insert(sprintf(
                'INSERT INTO %s.%s (%s) VALUES %s',
                $this->quotePgIdentifier($schema),
                $this->quotePgIdentifier($table),
                implode(', ', array_map(fn (string $column): string => $this->quotePgIdentifier($column), $insertColumns)),
                implode(', ', $placeholders),
            ), $bindings);
        }
    }

    private function createIndexes(string $schema): void
    {
        $schemaName = $this->quotePgIdentifier($schema);
        $indexes = [
            "CREATE INDEX IF NOT EXISTS legacy_docinfo_date_text_idx ON {$schemaName}.dbo__docinfo (\"DI_DATE\")",
            "CREATE INDEX IF NOT EXISTS legacy_docinfo_key_idx ON {$schemaName}.dbo__docinfo (\"DI_KEY\")",
            "CREATE INDEX IF NOT EXISTS legacy_docinfo_dt_idx ON {$schemaName}.dbo__docinfo (\"DI_DT\")",
            "CREATE INDEX IF NOT EXISTS legacy_docinfo_ref_idx ON {$schemaName}.dbo__docinfo (\"DI_REF\")",
            "CREATE INDEX IF NOT EXISTS legacy_doctype_key_idx ON {$schemaName}.dbo__doctype (\"DT_KEY\")",
            "CREATE INDEX IF NOT EXISTS legacy_transtkh_di_idx ON {$schemaName}.dbo__transtkh (\"TRH_DI\")",
            "CREATE INDEX IF NOT EXISTS legacy_transtkh_key_idx ON {$schemaName}.dbo__transtkh (\"TRH_KEY\")",
            "CREATE INDEX IF NOT EXISTS legacy_transtkd_trh_idx ON {$schemaName}.dbo__transtkd (\"TRD_TRH\")",
            "CREATE INDEX IF NOT EXISTS legacy_aroe_di_idx ON {$schemaName}.dbo__aroe (\"AROE_DI\")",
            "CREATE INDEX IF NOT EXISTS legacy_aroe_ar_idx ON {$schemaName}.dbo__aroe (\"AROE_AR\")",
            "CREATE INDEX IF NOT EXISTS legacy_arfile_key_idx ON {$schemaName}.dbo__arfile (\"AR_KEY\")",
            "CREATE INDEX IF NOT EXISTS legacy_skumaster_key_idx ON {$schemaName}.dbo__skumaster (\"SKU_KEY\")",
        ];

        foreach ($indexes as $sql) {
            DB::statement($sql);
        }
    }

    private function quotePgIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
