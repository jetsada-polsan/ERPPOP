<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'direction', 'cheque_no', 'bank_name', 'branch_id', 'bank_account_id',
    'amount', 'cheque_date', 'customer_id', 'supplier_id',
    'payment_document_id', 'status', 'deposited_at', 'cleared_at', 'remark',
])]
class Cheque extends Model
{
    public const STATUS_LABELS = [
        'on_hand' => 'ในมือ (รอนำฝาก)',
        'deposited' => 'นำฝากแล้ว (รอผ่าน)',
        'issued' => 'ออกเช็คแล้ว (รอตัดบัญชี)',
        'cleared' => 'ผ่านแล้ว',
        'bounced' => 'เช็คคืน/เด้ง',
        'cancelled' => 'ยกเลิก',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentDocument(): BelongsTo
    {
        return $this->belongsTo(PaymentDocument::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['cleared', 'bounced', 'cancelled'], true);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cheque_date' => 'date',
            'deposited_at' => 'date',
            'cleared_at' => 'date',
        ];
    }
}
