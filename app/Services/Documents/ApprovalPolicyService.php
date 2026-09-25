<?php

namespace App\Services\Documents;

use App\Models\User;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApprovalPolicyService
{
    public function authorize(string $type, int|float|string $amount, int $branchId, ?int $makerId, User $actor, string $basePermission): void
    {
        // Legacy/user-factory rows may leave is_active NULL; only an explicit false
        // means disabled. New production users are stored as true.
        if ($actor->is_active === false || ! $actor->hasPermission($basePermission)) {
            throw new RuntimeException('ไม่มีสิทธิ์อนุมัติเอกสาร');
        }
        if ($makerId && $makerId === $actor->id) {
            throw new RuntimeException('ผู้สร้างไม่สามารถอนุมัติเอกสารของตนเอง');
        }
        if ($actor->branch_id && (int) $actor->branch_id !== $branchId) {
            throw new RuntimeException('ไม่สามารถอนุมัติเอกสารต่างสาขา');
        }
        $rules = DB::table('approval_rules')->where('document_type', $type)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))->get();
        if ($rules->isEmpty()) {
            return;
        }
        foreach ($rules as $rule) {
            if (DecimalMath::compare($amount, $rule->min_amount) >= 0
                && ($rule->max_amount === null || DecimalMath::compare($amount, $rule->max_amount) <= 0)
                && $actor->hasPermission($rule->permission)) {
                return;
            }
        }
        throw new RuntimeException('วงเงินเกินสิทธิ์ตามตารางอนุมัติ');
    }
}
