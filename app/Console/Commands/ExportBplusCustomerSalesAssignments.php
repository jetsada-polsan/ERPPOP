<?php

namespace App\Console\Commands;

use App\Services\Mssql\InteractsWithMssql;
use Illuminate\Console\Command;

class ExportBplusCustomerSalesAssignments extends Command
{
    use InteractsWithMssql;

    protected $signature = 'bplus:export-customer-sales
        {output : Destination CSV path}
        {--months=12 : Number of recent months used to identify the predominant route and salesperson}';

    protected $description = 'Export each Business Plus customer predominant route and salesperson as UTF-8 CSV';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $rows = $this->fetchAll(<<<SQL
            WITH activity AS (
                SELECT
                    RTRIM(ar.AR_CODE) AS CUSTOMER_CODE,
                    RTRIM(dt.DT_DOCCODE) AS ROUTE_CODE,
                    RTRIM(COALESCE(sm.SLMN_CODE, '')) AS LEGACY_SALESMAN_CODE,
                    RTRIM(COALESCE(sm.SLMN_NAME, '')) AS LEGACY_SALESMAN_NAME,
                    COUNT(DISTINCT di.DI_KEY) AS DOCUMENT_COUNT,
                    MAX(di.DI_DATE) AS LAST_DOCUMENT_DATE
                FROM DOCINFO di
                INNER JOIN DOCTYPE dt ON dt.DT_KEY = di.DI_DT
                INNER JOIN AROE oe ON oe.AROE_DI = di.DI_KEY
                INNER JOIN ARFILE ar ON ar.AR_KEY = oe.AROE_AR
                LEFT JOIN SALESMAN sm ON sm.SLMN_KEY = oe.AROE_SLMN
                WHERE dt.DT_PROPERTIES = 207
                  AND dt.DT_ENABLE = 'Y'
                  AND dt.DT_DOCCODE LIKE 'B%'
                  AND di.DI_DATE >= DATEADD(month, -{$months}, GETDATE())
                GROUP BY ar.AR_CODE, dt.DT_DOCCODE, sm.SLMN_CODE, sm.SLMN_NAME
            ),
            ranked AS (
                SELECT activity.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY CUSTOMER_CODE
                        ORDER BY DOCUMENT_COUNT DESC, LAST_DOCUMENT_DATE DESC, ROUTE_CODE
                    ) AS RN
                FROM activity
            )
            SELECT CUSTOMER_CODE, ROUTE_CODE, LEGACY_SALESMAN_CODE,
                   LEGACY_SALESMAN_NAME, DOCUMENT_COUNT, LAST_DOCUMENT_DATE
            FROM ranked
            WHERE RN = 1
            ORDER BY CUSTOMER_CODE
        SQL);

        $path = (string) $this->argument('output');
        $handle = fopen($path, 'wb');
        if (! $handle) {
            $this->error("Cannot write CSV: {$path}");

            return self::FAILURE;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'customer_code', 'route_code', 'legacy_salesman_code',
            'legacy_salesman_name', 'document_count', 'last_document_date',
        ]);
        foreach ($rows as $row) {
            fputcsv($handle, [
                trim((string) $row['CUSTOMER_CODE']),
                trim((string) $row['ROUTE_CODE']),
                trim((string) $row['LEGACY_SALESMAN_CODE']),
                trim((string) $row['LEGACY_SALESMAN_NAME']),
                $row['DOCUMENT_COUNT'],
                $row['LAST_DOCUMENT_DATE'],
            ]);
        }
        fclose($handle);

        $this->info('Exported '.count($rows)." customer assignments to {$path}");

        return self::SUCCESS;
    }
}
