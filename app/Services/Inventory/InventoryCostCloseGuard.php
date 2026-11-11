<?php

namespace App\Services\Inventory;

use App\Models\InventoryCostClosePeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryCostCloseGuard
{
    private static ?bool $hasTable = null;
    private static array $closedPeriods = [];

    public static function flushCache(): void
    {
        self::$hasTable = null;
        self::$closedPeriods = [];
    }

    public function assertOpen(string|CarbonInterface|null $date, string $field = 'movement_date'): void
    {
        if (self::$hasTable === null) {
            self::$hasTable = Schema::hasTable('inventory_cost_close_periods');
        }
        if (! $date || ! self::$hasTable) {
            return;
        }

        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
        $period = substr($dateString, 0, 7);

        if (! array_key_exists($period, self::$closedPeriods)) {
            self::$closedPeriods[$period] = InventoryCostClosePeriod::closed()->where('period', $period)->exists();
        }

        if (! self::$closedPeriods[$period]) {
            return;
        }

        throw ValidationException::withMessages([
            $field => "วันที่ {$dateString} อยู่ในงวดที่ปิดต้นทุนสินค้าแล้ว ({$period}) ไม่สามารถบันทึกการเคลื่อนไหวสต๊อกย้อนหลังได้ ต้องเปิดงวดก่อนถ้าจำเป็นต้องแก้ไข",
        ]);
    }
}
