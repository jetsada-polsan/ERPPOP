<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_document_id', 'seq', 'product_id', 'source_stock_lot_id', 'return_disposition',
    'warehouse_location_id', 'qty', 'system_qty', 'counted_qty', 'unit_id',
    'unit_price', 'unit_cost', 'cost_amount', 'vat_amount', 'lot_no', 'serial_no', 'expire_date', 'manufacture_date',
])]
class StockDocumentItem extends Model
{
    public $timestamps = false;

    public function stockDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceStockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'source_stock_lot_id');
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'unit_id');
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'system_qty' => 'decimal:4',
            'counted_qty' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'cost_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'manufacture_date' => 'date',
            'expire_date' => 'date',
        ];
    }
}
