<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ใบเสนอราคาจากผู้ขายรายหนึ่ง สำหรับใบขอซื้อใบหนึ่ง */
class PurchaseQuote extends Model
{
    protected $fillable = [
        'purchase_order_id', 'supplier_id', 'total_amount', 'valid_until', 'reference',
        'note', 'is_selected', 'selection_reason', 'quoted_by', 'selected_by', 'selected_at',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'selected_at' => 'datetime',
            'is_selected' => 'boolean',
            'total_amount' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseQuoteItem::class);
    }
}
