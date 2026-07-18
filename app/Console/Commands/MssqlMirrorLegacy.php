<?php

namespace App\Console\Commands;

use App\Services\Mssql\LegacyMirrorSourceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MssqlMirrorLegacy extends Command
{
    protected $signature = 'mssql:mirror-legacy
        {--table=* : MSSQL table name to mirror. Repeatable. When omitted, mirrors all tables.}
        {--like= : MSSQL table LIKE pattern, e.g. H2026%}
        {--month= : Keep only monthly POS tables for this YYYYMM. Non-month tables are still included.}
        {--structure-all : With --month, create every MSSQL table but copy data only for that monthly POS period.}
        {--reset-schema : Drop and recreate the target PostgreSQL schema before mirroring.}
        {--schema=legacy : PostgreSQL schema to write to.}
        {--chunk=500 : Rows per insert chunk.}
        {--skip-existing : Skip target tables whose PostgreSQL row count already matches MSSQL.}
        {--dry-run : Show selected tables and row counts without writing.}
        {--skip-data : Create tables only, no row copy.}';

    protected $description = 'Mirror legacy BPlus MSSQL tables into PostgreSQL as text columns under a separate schema.';

    public function handle(LegacyMirrorSourceService $source): int
    {
        $targetSchema = (string) $this->option('schema');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $month = trim((string) ($this->option('month') ?? ''));
        $dryRun = (bool) $this->option('dry-run');
        $skipData = (bool) $this->option('skip-data');
        $skipExisting = (bool) $this->option('skip-existing');
        $structureAll = (bool) $this->option('structure-all');
        $resetSchema = (bool) $this->option('reset-schema');

        $tables = $this->selectedTables($source, $month, $structureAll);
        if ($tables === []) {
            $this->warn('No MSSQL tables matched.');

            return self::SUCCESS;
        }

        $this->info('Selected '.count($tables).' table(s).');

        $totalRows = 0;
        $failed = 0;

        if (! $dryRun && $resetSchema) {
            DB::statement('DROP SCHEMA IF EXISTS '.$this->quotePgIdentifier($targetSchema).' CASCADE');
        }

        if (! $dryRun) {
            DB::statement('CREATE SCHEMA IF NOT EXISTS '.$this->quotePgIdentifier($targetSchema));
        }

        foreach ($tables as $index => $table) {
            $label = sprintf('[%d/%d] %s.%s', $index + 1, count($tables), $table['schema'], $table['name']);

            try {
                $columns = $source->columns($table['schema'], $table['name']);
                $copyData = $this->shouldCopyData($table['name'], $month, $structureAll, $skipData);
                $rowCount = ($dryRun || $copyData || $skipExisting)
                    ? $source->countRows($table['schema'], $table['name'])
                    : 0;
                $totalRows += $rowCount;

                if ($dryRun) {
                    $mode = $copyData ? 'copy' : 'structure';
                    $this->line(sprintf('%-55s %10d row(s) %s', $label, $rowCount, $mode));
                    continue;
                }

                $pgTable = $this->pgTableName($table['schema'], $table['name']);
                if ($skipExisting) {
                    $targetCount = $this->targetRowCount($targetSchema, $pgTable);
                    if ($targetCount === $rowCount) {
                        $this->line(sprintf('%-55s skipped, already %d row(s)', $label, $rowCount));
                        continue;
                    }
                }

                $this->createTargetTable($targetSchema, $pgTable, $columns);

                if (! $copyData) {
                    $this->line(sprintf('%-55s table ready, data skipped', $label));
                    continue;
                }

                DB::statement(sprintf(
                    'TRUNCATE TABLE %s.%s',
                    $this->quotePgIdentifier($targetSchema),
                    $this->quotePgIdentifier($pgTable),
                ));

                $copied = 0;
                for ($offset = 0; $offset < $rowCount; $offset += $chunkSize) {
                    $rows = $source->rows($table['schema'], $table['name'], $offset, $chunkSize);
                    $this->insertRows($targetSchema, $pgTable, $columns, $rows);
                    $copied += count($rows);

                    if ($copied > 0 && ($copied % 50000) < $chunkSize && $copied < $rowCount) {
                        $this->line(sprintf('%-55s %10d/%d row(s)', $label, $copied, $rowCount));
                    }
                }

                $this->line(sprintf('%-55s %10d row(s)', $label, $copied));
            } catch (Throwable $e) {
                $failed++;
                $message = $e->getMessage();
                if (strlen($message) > 500) {
                    $message = substr($message, 0, 500).'...';
                }
                $this->error($label.' failed: '.$message);
            }
        }

        $this->newLine();
        $this->info('MSSQL rows selected: '.$totalRows);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, array{name: string, schema: string, rows: int|null}>
     */
    private function selectedTables(LegacyMirrorSourceService $source, string $month, bool $structureAll): array
    {
        $requestedTables = array_values(array_filter(array_map('trim', (array) $this->option('table'))));
        $like = trim((string) ($this->option('like') ?? ''));

        $tables = $source->tables($like !== '' ? $like : null);

        if ($requestedTables !== []) {
            $wanted = array_fill_keys(array_map('strtoupper', $requestedTables), true);
            $tables = array_values(array_filter(
                $tables,
                fn (array $table): bool => isset($wanted[strtoupper($table['name'])]),
            ));
        }

        if ($month !== '' && ! $structureAll) {
            $tables = array_values(array_filter($tables, function (array $table) use ($month): bool {
                $name = strtoupper($table['name']);

                return ! preg_match('/^[CDHLPS]\d{6}$/', $name) || substr($name, 1) === $month;
            }));
        }

        return $tables;
    }

    private function shouldCopyData(string $table, string $month, bool $structureAll, bool $skipData): bool
    {
        if ($skipData) {
            return false;
        }

        if (! $structureAll || $month === '') {
            return true;
        }

        return preg_match('/^[CDHLPS]'.preg_quote($month, '/').'$/i', $table) === 1;
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

        foreach (array_chunk($rows, $maxRowsPerInsert) as $rowChunk) {
            $this->insertRowChunk($schema, $table, $columnNames, $insertColumns, $rowChunk);
        }
    }

    /**
     * @param  array<int, string>  $columnNames
     * @param  array<int, string>  $insertColumns
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertRowChunk(string $schema, string $table, array $columnNames, array $insertColumns, array $rows): void
    {
        $placeholders = [];
        $bindings = [];
        $syncedAt = now()->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $placeholders[] = '('.implode(', ', array_fill(0, count($insertColumns), '?')).')';

            foreach ($columnNames as $columnName) {
                $value = $row[$columnName] ?? null;
                $bindings[] = $value === null ? null : (string) $value;
            }

            $bindings[] = $syncedAt;
        }

        $sql = sprintf(
            'INSERT INTO %s.%s (%s) VALUES %s',
            $this->quotePgIdentifier($schema),
            $this->quotePgIdentifier($table),
            implode(', ', array_map(fn (string $column): string => $this->quotePgIdentifier($column), $insertColumns)),
            implode(', ', $placeholders),
        );

        DB::insert($sql, $bindings);
    }

    private function targetRowCount(string $schema, string $table): ?int
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$schema, $table],
        );

        if ($exists === null) {
            return null;
        }

        $row = DB::selectOne(sprintf(
            'SELECT COUNT(*) AS row_count FROM %s.%s',
            $this->quotePgIdentifier($schema),
            $this->quotePgIdentifier($table),
        ));

        return (int) ($row->row_count ?? 0);
    }

    private function pgTableName(string $sourceSchema, string $sourceTable): string
    {
        return strtolower($sourceSchema.'__'.$sourceTable);
    }

    private function quotePgIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
