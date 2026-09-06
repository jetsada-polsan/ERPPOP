<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_transfer_receipt_id', 'product_id', 'expected_qty', 'scanned_qty', 'note'])]
class StockTransferReceiptItem extends Model
{
    public $timestamps = false;

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockTransferReceipt::class, 'stock_transfer_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'expected_qty' => 'decimal:8',
            'scanned_qty' => 'decimal:8',
        ];
    }
}
