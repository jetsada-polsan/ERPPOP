<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'bank_name', 'account_no', 'account_name', 'chart_of_account_id'])]
class BankAccount extends Model
{
    public $timestamps = false;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** บัญชีในผัง GL ที่เงินเข้า/ออกบัญชีธนาคารนี้ลง (ว่าง = ใช้บัญชีธนาคารกลาง) */
    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(BankStatement::class);
    }

    public function paymentLines(): HasMany
    {
        return $this->hasMany(PaymentLine::class);
    }
}
