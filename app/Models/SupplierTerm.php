<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_id',
    'name',
    'start_date',
    'end_date',
    'price_table_id',
    'credit_days',
    'trade_discount_percent',
    'cash_discount_percent',
    'is_default',
    'notes',
])]
class SupplierTerm extends Model
{
    protected static function booted(): void
    {
        static::saving(function (SupplierTerm $term) {
            // เมื่อตั้งรายการนี้เป็นค่าเริ่มต้น ให้ยกเลิกค่าเริ่มต้นเดิมของผู้ขายรายนี้ทั้งหมด (One Default)
            if ($term->is_default && $term->supplier_id) {
                static::where('supplier_id', $term->supplier_id)
                    ->where('id', '!=', (int) ($term->id ?? 0))
                    ->update(['is_default' => false]);
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function priceTable(): BelongsTo
    {
        return $this->belongsTo(PriceTable::class);
    }

    /** ตรวจสอบว่ารูปแบบข้อตกลงเป็นแบบไม่คิดภาษีมูลค่าเพิ่ม (N- = no VAT ตามมาตรฐาน BPlus) */
    public function isNoVat(): bool
    {
        return str_starts_with(strtoupper(trim((string) $this->name)), 'N-');
    }

    /** ตรวจสอบว่าข้อตกลงมีผลบังคับใช้ ณ วันที่กำหนดหรือไม่ */
    public function isActiveOn(?CarbonInterface $date = null): bool
    {
        $d = $date ?? now();
        $startOk = ! $this->start_date || $this->start_date->lte($d);
        $endOk = ! $this->end_date || $this->end_date->gte($d);

        return $startOk && $endOk;
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'credit_days' => 'integer',
            'trade_discount_percent' => 'decimal:2',
            'cash_discount_percent' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }
}
