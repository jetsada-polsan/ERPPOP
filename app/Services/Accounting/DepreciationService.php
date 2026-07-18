<?php

namespace App\Services\Accounting;

use App\Models\DepreciationRecord;
use App\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * คิดค่าเสื่อมราคาแบบเส้นตรงรายเดือนสำหรับทรัพย์สินที่ยังใช้งาน กันคิดซ้ำงวดเดิม
 * และกันคิดเกินมูลค่าที่เสื่อมได้ (ทุน - ซาก). งวดสุดท้ายปัดเศษให้พอดี.
 */
class DepreciationService
{
    /**
     * คิดค่าเสื่อมให้ทุกทรัพย์สินที่ active สำหรับงวด (เดือน) ที่กำหนด
     *
     * @return array{posted:int, amount:float, skipped:int}
     */
    public function runForPeriod(Carbon $period): array
    {
        $periodEnd = $period->copy()->endOfMonth()->toDateString();
        $posted = 0;
        $skipped = 0;
        $totalAmount = 0.0;

        FixedAsset::where('status', 'active')->each(function (FixedAsset $asset) use ($periodEnd, &$posted, &$skipped, &$totalAmount) {
            $result = $this->postOne($asset, $periodEnd);
            if ($result > 0) {
                $posted++;
                $totalAmount += $result;
            } else {
                $skipped++;
            }
        });

        return ['posted' => $posted, 'amount' => round($totalAmount, 2), 'skipped' => $skipped];
    }

    /**
     * คิดค่าเสื่อม 1 งวดสำหรับ 1 ทรัพย์สิน คืนจำนวนที่คิด (0 = ข้าม)
     */
    public function postOne(FixedAsset $asset, string $periodEnd): float
    {
        // ยังไม่ถึงวันได้มา หรือ ปิดค่าเสื่อมครบแล้ว = ข้าม
        if ($asset->status !== 'active' || $asset->acquired_date->gt(Carbon::parse($periodEnd))) {
            return 0.0;
        }

        // งวดนี้คิดไปแล้ว = ข้าม (idempotent)
        if (DepreciationRecord::where('fixed_asset_id', $asset->id)->where('period_date', $periodEnd)->exists()) {
            return 0.0;
        }

        $remaining = round($asset->depreciableBase() - (float) $asset->accumulated_depreciation, 2);
        if ($remaining <= 0) {
            $asset->update(['status' => 'fully_depreciated']);

            return 0.0;
        }

        // งวดสุดท้ายคิดเท่าที่เหลือ ไม่ให้เกิน
        $amount = min($asset->monthlyDepreciation(), $remaining);
        if ($amount <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($asset, $periodEnd, $amount) {
            $newAccumulated = round((float) $asset->accumulated_depreciation + $amount, 2);
            $bookValue = round((float) $asset->cost - $newAccumulated, 2);

            DepreciationRecord::create([
                'fixed_asset_id' => $asset->id,
                'period_date' => $periodEnd,
                'amount' => $amount,
                'accumulated_after' => $newAccumulated,
                'book_value_after' => $bookValue,
            ]);

            $asset->update([
                'accumulated_depreciation' => $newAccumulated,
                'status' => $newAccumulated >= $asset->depreciableBase() - 0.01 ? 'fully_depreciated' : 'active',
            ]);

            return $amount;
        });
    }
}
