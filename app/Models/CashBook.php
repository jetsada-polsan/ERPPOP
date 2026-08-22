<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * สมุดเงินสดรายสาขา — เดินรายการโดย CashBookPostingService เป็นหลัก
 * แถวที่กรอกมือได้มีเฉพาะ source_type = 'adjustment' ซึ่งต้องมีเหตุผลและผู้อนุมัติ
 */
#[Fillable([
    'branch_id', 'entry_date', 'description', 'cash_in', 'cash_out', 'running_balance',
    'source_type', 'source_id', 'source_key', 'pos_terminal_id', 'pos_shift_id',
    'reason', 'created_by', 'approved_by', 'approved_at',
])]
class CashBook extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'approved_at' => 'datetime',
            'cash_in' => 'decimal:4',
            'cash_out' => 'decimal:4',
            'running_balance' => 'decimal:4',
        ];
    }
}
