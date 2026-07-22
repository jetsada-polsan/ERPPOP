<?php

namespace App\Services\Inventory;

use App\Models\InventoryCostClosePeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryCostCloseGuard
{
    public function assertOpen(string|CarbonInterface|null $date, string $field = 'movement_date'): void
    {
        if (! $date || ! Schema::hasTable('inventory_cost_close_periods')) {
            return;
        }

        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
        $period = substr($dateString, 0, 7);

        if (! InventoryCostClosePeriod::closed()->where('period', $period)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => "วันที่ {$dateString} อยู่ในงวดที่ปิดต้นทุนสินค้าแล้ว ({$period}) ไม่สามารถบันทึกการเคลื่อนไหวสต๊อกย้อนหลังได้ ต้องเปิดงวดก่อนถ้าจำเป็นต้องแก้ไข",
        ]);
    }
}
