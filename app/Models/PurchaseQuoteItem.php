<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseQuoteItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_quote_id', 'purchase_order_item_id', 'unit_price', 'note'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:4'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuote::class, 'purchase_quote_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
