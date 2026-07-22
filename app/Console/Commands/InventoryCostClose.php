<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\InventoryCostClosePeriod;
use App\Services\Inventory\InventoryCostCloseService;
use Illuminate\Console\Command;
use Throwable;

class InventoryCostClose extends Command
{
    protected $signature = 'erp:inventory-cost-close {period : Accounting period YYYY-MM}
        {--reopen : Reopen an already-closed period instead of closing it}
        {--user= : user id recorded as closed_by and in the audit log}';

    protected $description = 'Freeze month-end inventory quantities, average cost, and ending value';

    public function handle(InventoryCostCloseService $service): int
    {
        $period = (string) $this->argument('period');
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        try {
            if ($this->option('reopen')) {
                $service->reopen($period);
                $this->audit($period, 'reopen', $userId, ['status' => 'closed'], ['status' => 'open']);
                $this->info("เปิดงวดต้นทุนสินค้า {$period} แล้ว — บันทึกสต๊อกย้อนหลังในงวดนี้ได้อีกครั้ง");

                return self::SUCCESS;
            }

            $count = $service->close($period, $userId);
            $this->audit($period, 'close', $userId, ['status' => 'open'], ['status' => 'closed']);
            $this->info("ปิดต้นทุนสินค้า {$count} รายการแล้ว ({$period}) — ห้ามบันทึกการเคลื่อนไหวสต๊อกย้อนหลังในงวดนี้อีก");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function audit(string $period, string $action, ?int $userId, array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'table_name' => 'inventory_cost_close_periods',
            'record_id' => InventoryCostClosePeriod::where('period', $period)->value('id'),
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
