<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * เกณฑ์เติมสินค้าต่อสาขา (Min/Max/Reorder point ต่อสินค้า x สาขา) ใช้โดย
 * BranchReplenishmentService เพื่อคำนวณว่าสาขาไหนควรได้รับสินค้าอะไรเท่าไรจาก
 * คลังต้นทาง แยกจาก products.minimum_stock/maximum_stock ซึ่งเป็นค่ากลางที่
 * ReplenishmentService ใช้สำหรับการสั่งซื้อจาก supplier เท่านั้น
 */
#[Fillable(['branch_id', 'product_id', 'minimum_stock', 'maximum_stock', 'reorder_point', 'is_active'])]
class BranchStockPolicy extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'decimal:8',
            'maximum_stock' => 'decimal:8',
            'reorder_point' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }
}
