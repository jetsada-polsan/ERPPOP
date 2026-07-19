<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountingPeriodGuard
{
    public function assertOpen(string|CarbonInterface|null $date, ?int $branchId = null, string $field = 'doc_date'): void
    {
        if (! $date || ! Schema::hasTable('accounting_periods')) {
            return;
        }

        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;

        $period = AccountingPeriod::closed()
            ->whereDate('starts_on', '<=', $dateString)
            ->whereDate('ends_on', '>=', $dateString)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        if (! $period) {
            return;
        }

        $scope = $period->branch_id ? 'สาขาที่กำหนด' : 'ทั้งบริษัท';

        throw ValidationException::withMessages([
            $field => "วันที่ {$dateString} อยู่ในงวดปิด [{$period->name}] ({$scope}) ไม่สามารถเพิ่ม แก้ไข ยกเลิก หรือลบรายการได้",
        ]);
    }
}
