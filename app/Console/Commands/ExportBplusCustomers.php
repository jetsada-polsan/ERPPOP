<?php

namespace App\Console\Commands;

use App\Services\Mssql\InteractsWithMssql;
use Illuminate\Console\Command;

class ExportBplusCustomers extends Command
{
    use InteractsWithMssql;

    protected $signature = 'bplus:export-customers {output : Destination UTF-8 CSV path}';

    protected $description = 'Export the Business Plus customer master using a read-only SELECT';

    public function handle(): int
    {
        $rows = $this->fetchAll(
            "SELECT CAST(RTRIM(AR_CODE) AS VARCHAR(12)) AS CUSTOMER_CODE,
                    CAST(RTRIM(AR_NAME) AS VARCHAR(100)) AS CUSTOMER_NAME,
                    AR_ENABLE AS IS_ACTIVE
             FROM ARFILE
             WHERE RTRIM(COALESCE(AR_CODE, '')) <> ''
             ORDER BY AR_CODE"
        );

        $path = (string) $this->argument('output');
        $handle = fopen($path, 'wb');
        if (! $handle) {
            $this->error("Cannot write CSV: {$path}");

            return self::FAILURE;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['customer_code', 'customer_name', 'is_active']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                trim((string) $row['CUSTOMER_CODE']),
                trim((string) $row['CUSTOMER_NAME']),
                (string) $row['IS_ACTIVE'] === 'Y' ? '1' : '0',
            ]);
        }
        fclose($handle);

        $this->info('Exported '.count($rows)." customers to {$path}");

        return self::SUCCESS;
    }
}
