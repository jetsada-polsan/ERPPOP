<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name_th', 'name_en', 'tax_id', 'tax_branch', 'is_active'])]
class Supplier extends Model
{
    public function addresses(): HasMany
    {
        return $this->hasMany(SupplierAddress::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedger::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
