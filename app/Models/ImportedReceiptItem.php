<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'batch_id', 'receipt_id', 'legacy_psd_key', 'line_no', 'product_code', 'barcode', 'sku_code',
    'product_id', 'qty', 'unit_price', 'discount_amount', 'vat_amount', 'net_amount',
    'raw_data', 'mapping_status',
])]
class ImportedReceiptItem extends Model
{
    public $timestamps = false;

    // mapping_status: pending/mapped/not_found
    public const MAPPING_PENDING = 'pending';

    public const MAPPING_MAPPED = 'mapped';

    public const MAPPING_NOT_FOUND = 'not_found';

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ImportedReceipt::class, 'receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'raw_data' => 'array',
        ];
    }
}
