<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryCostCloseService;
use Illuminate\Console\Command;
use Throwable;

class InventoryCostClose extends Command
{
    protected $signature = 'erp:inventory-cost-close {period : Accounting period YYYY-MM}';

    protected $description = 'Freeze month-end inventory quantities, average cost, and ending value';

    public function handle(InventoryCostCloseService $service): int
    {
        try {
            $count = $service->close($this->argument('period'));
            $this->info("ปิดต้นทุนสินค้า {$count} รายการแล้ว");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
