<?php

namespace App\Console\Commands;

use App\Services\Bplus\BplusDocumentMaterializer;
use Illuminate\Console\Command;

class BplusMaterialize extends Command
{
    protected $signature = 'bplus:materialize {route=B18 : รหัสสายขาย}';

    protected $description = 'Turn the BPlus copies of one sales route into ERP bookings, credit sales and receipts (no stock, no GL)';

    public function handle(BplusDocumentMaterializer $materializer): int
    {
        $result = $materializer->run((string) $this->argument('route'));
        $this->info("สร้าง/อัปเดตเอกสาร ERP {$result['documents']} ใบ");
        foreach ($result['problems'] as $ref => $problem) {
            $this->warn("{$ref}: {$problem}");
        }

        return self::SUCCESS;
    }
}
