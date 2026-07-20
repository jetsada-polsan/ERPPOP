<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name_th', 'name_en', 'tax_id', 'tax_branch', 'branch_id', 'credit_limit', 'is_active', 'pending_credit_limit', 'credit_limit_requested_by', 'credit_limit_requested_at'])]
class Customer extends Model
{
    use SoftDeletes;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creditLimitRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credit_limit_requested_by');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function openItems(): HasMany
    {
        return $this->hasMany(CustomerOpenItem::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function paymentDocuments(): HasMany
    {
        return $this->hasMany(PaymentDocument::class);
    }

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'pending_credit_limit' => 'decimal:4',
            'credit_limit_requested_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
