<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name_th', 'name_en', 'tax_id', 'tax_branch', 'withholding_tax_form', 'is_active'])]
class Supplier extends Model
{
    public const WHT_NONE = 'none';
    public const WHT_PND3 = 'pnd3';
    public const WHT_PND53 = 'pnd53';

    public function addresses(): HasMany
    {
        return $this->hasMany(SupplierAddress::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(SupplierTerm::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function defaultTerm(): ?SupplierTerm
    {
        return $this->terms()->where('is_default', true)->first();
    }

    /** แปลงชื่อแบบภาษีหัก ณ ที่จ่ายเป็นข้อความภาษาไทย */
    public function withholdingTaxFormLabel(): string
    {
        return match (strtolower((string) $this->withholding_tax_form)) {
            'pnd3', 'ภ.ง.ด.3' => 'ภ.ง.ด.3',
            'pnd53', 'ภ.ง.ด.53' => 'ภ.ง.ด.53',
            default => 'ไม่มี',
        };
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
