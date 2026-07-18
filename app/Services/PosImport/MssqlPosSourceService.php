<?php

namespace App\Services\PosImport;

use App\Services\Mssql\InteractsWithMssql;
use Carbon\Carbon;
use InvalidArgumentException;
use PDO;

/**
 * Read-only access to the legacy BPlus MSSQL server's POS tables (H/D/P, monthly
 * partitioned as H202507, D202507, P202507, ...). Uses raw PDO over the Windows
 * built-in "SQL Server" ODBC driver via pdo_odbc, since no native sqlsrv extension
 * is installed. Never writes to MSSQL.
 */
class MssqlPosSourceService
{
    use InteractsWithMssql;

    /**
     * Monthly table suffix used by BPlus (e.g. "202507") for a given sale date.
     */
    private function monthSuffix(Carbon $saleDate): string
    {
        return $saleDate->format('Ym');
    }

    /**
     * Validates a table name built from a trusted Carbon date before interpolating
     * it into SQL (table names cannot be bound as PDO parameters).
     */
    private function assertSafeTableName(string $table): void
    {
        if (! preg_match('/^[A-Z]20\d{4}$/', $table)) {
            throw new InvalidArgumentException("Refusing to query unexpected table name: {$table}");
        }
    }

    /**
     * Fetch POS receipt headers (PSH_*) for one POS terminal and one sale date.
     *
     * PSH_POS stores BRANCH.BR_KEY (a surrogate id), not BRANCH.BR_CODE - e.g.
     * branch code "0002" has BR_KEY 101, not 2. So $posCode (a BR_CODE like
     * "0001") must be resolved via a real BRANCH lookup, not by stripping zeros.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchReceipts(string $posCode, Carbon $saleDate): array
    {
        $table = 'H'.$this->monthSuffix($saleDate);
        $this->assertSafeTableName($table);

        $sql = "SELECT h.* FROM {$table} h
                JOIN BRANCH br ON br.BR_KEY = h.PSH_POS
                WHERE br.BR_CODE = :pos_code
                  AND CAST(h.PSH_DATE AS DATE) = :sale_date
                ORDER BY h.PSH_KEY";

        return $this->fetchAll($sql, [
            'pos_code' => $posCode,
            'sale_date' => $saleDate->toDateString(),
        ]);
    }

    /**
     * Fetch line items (PSD_*) for a set of header keys (PSH_KEY) within one sale date's month.
     *
     * @param  array<int, int>  $pshKeys
     * @return array<int, array<string, mixed>>
     */
    public function fetchItems(array $pshKeys, Carbon $saleDate): array
    {
        if ($pshKeys === []) {
            return [];
        }

        $table = 'D'.$this->monthSuffix($saleDate);
        $this->assertSafeTableName($table);

        // PSD_SKU/PSD_GOODS are internal surrogate keys (SKUMASTER.SKU_KEY /
        // GOODSMASTER.GOODS_KEY), not the portable codes products will be keyed by
        // once master data is migrated - resolve to SKU_CODE/GOODS_CODE here so
        // staged rows are joinable against products.sku_code later.
        $placeholders = implode(',', array_fill(0, count($pshKeys), '?'));
        $sql = "SELECT d.*, sm.SKU_CODE AS RESOLVED_SKU_CODE, gm.GOODS_CODE AS RESOLVED_GOODS_CODE
                FROM {$table} d
                LEFT JOIN SKUMASTER sm ON sm.SKU_KEY = d.PSD_SKU
                LEFT JOIN GOODSMASTER gm ON gm.GOODS_KEY = d.PSD_GOODS
                WHERE d.PSD_PSH IN ({$placeholders})
                ORDER BY d.PSD_PSH, d.PSD_KEY";

        return $this->fetchAll($sql, array_values($pshKeys));
    }

    /**
     * Fetch payment lines (PSP_*) for a set of header keys (PSH_KEY) within one sale date's month.
     *
     * @param  array<int, int>  $pshKeys
     * @return array<int, array<string, mixed>>
     */
    public function fetchPayments(array $pshKeys, Carbon $saleDate): array
    {
        if ($pshKeys === []) {
            return [];
        }

        $table = 'P'.$this->monthSuffix($saleDate);
        $this->assertSafeTableName($table);

        $placeholders = implode(',', array_fill(0, count($pshKeys), '?'));
        $sql = "SELECT * FROM {$table} WHERE PSP_PSH IN ({$placeholders}) ORDER BY PSP_PSH, PSP_KEY";

        return $this->fetchAll($sql, array_values($pshKeys));
    }

    /**
     * Look up payment type names (PMT_KEY -> PMT_NAME) for display/mapping purposes.
     *
     * @return array<int, string> keyed by PMT_KEY
     */
    public function fetchPaymentTypeNames(): array
    {
        $names = [];
        foreach ($this->fetchAll('SELECT PMT_KEY, PMT_NAME FROM PAYMENTTYPE') as $row) {
            $names[(int) $row['PMT_KEY']] = trim((string) $row['PMT_NAME']);
        }

        return $names;
    }

    /**
     * Find every POS/day combination that exists in the legacy monthly HYYYYMM
     * tables. This lets the import command migrate all historical POS receipts
     * without guessing date ranges or terminals by hand.
     *
     * @return array<int, array{pos_code: string, sale_date: string, receipt_count: int}>
     */
    public function fetchAvailableSaleDays(?Carbon $from = null, ?Carbon $to = null, array $posCodes = []): array
    {
        $tables = $this->fetchReceiptHeaderTables();
        $rows = [];

        foreach ($tables as $table) {
            if (! preg_match('/^H(20\d{4})$/', $table, $match)) {
                continue;
            }

            $month = Carbon::createFromFormat('Ym', $match[1])->startOfMonth();
            if ($from && $month->copy()->endOfMonth()->lt($from->copy()->startOfDay())) {
                continue;
            }
            if ($to && $month->copy()->startOfMonth()->gt($to->copy()->endOfDay())) {
                continue;
            }

            $this->assertSafeTableName($table);

            $where = [];
            $params = [];
            if ($from) {
                $where[] = 'CAST(h.PSH_DATE AS DATE) >= ?';
                $params[] = $from->toDateString();
            }
            if ($to) {
                $where[] = 'CAST(h.PSH_DATE AS DATE) <= ?';
                $params[] = $to->toDateString();
            }
            if ($posCodes !== []) {
                $where[] = 'br.BR_CODE IN ('.implode(',', array_fill(0, count($posCodes), '?')).')';
                array_push($params, ...array_values($posCodes));
            }

            $sql = "SELECT br.BR_CODE AS pos_code,
                           CAST(h.PSH_DATE AS DATE) AS sale_date,
                           COUNT(*) AS receipt_count
                    FROM {$table} h
                    JOIN BRANCH br ON br.BR_KEY = h.PSH_POS";
            if ($where !== []) {
                $sql .= ' WHERE '.implode(' AND ', $where);
            }
            $sql .= ' GROUP BY br.BR_CODE, CAST(h.PSH_DATE AS DATE)
                      ORDER BY sale_date, pos_code';

            foreach ($this->fetchAll($sql, $params) as $row) {
                $rows[] = [
                    'pos_code' => trim((string) $row['pos_code']),
                    'sale_date' => Carbon::parse($row['sale_date'])->toDateString(),
                    'receipt_count' => (int) $row['receipt_count'],
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['sale_date'], $a['pos_code']] <=> [$b['sale_date'], $b['pos_code']]);

        return $rows;
    }

    /** @return array<int, string> */
    private function fetchReceiptHeaderTables(): array
    {
        return array_map(
            fn ($row) => (string) $row['name'],
            $this->fetchAll("SELECT name FROM sys.tables WHERE name LIKE 'H20%' ORDER BY name")
        );
    }
}
