<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'name', 'qr_type', 'bank_account_id', 'merchant_ref', 'payload_template', 'is_active'])]
class QrPaymentConfig extends Model
{
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
