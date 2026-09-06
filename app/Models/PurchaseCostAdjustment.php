<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// หลักฐานการปรับต้นทุนซื้อย้อนหลังต่อ Lot หนึ่งครั้ง (immutable audit record - ไม่มีการ
// แก้ไข/ลบ) ดู PurchaseCostAdjustmentService สำหรับตรรกะการคำนวณและข้อจำกัด
#[Fillable([
    'document_id', 'stock_lot_id', 'product_id', 'old_unit_cost', 'new_unit_cost',
    'remaining_qty_adjusted', 'consumed_qty', 'unconfirmed_variance_amount', 'reason', 'created_by',
])]
class PurchaseCostAdjustment extends Model
{
    const UPDATED_AT = null;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'old_unit_cost' => 'decimal:8',
            'new_unit_cost' => 'decimal:8',
            'remaining_qty_adjusted' => 'decimal:8',
            'consumed_qty' => 'decimal:8',
            'unconfirmed_variance_amount' => 'decimal:8',
            'created_at' => 'datetime',
        ];
    }
}
