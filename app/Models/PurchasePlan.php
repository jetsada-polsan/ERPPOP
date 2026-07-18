<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan_no', 'product_id', 'supplier_id', 'suggested_qty', 'target_stock_qty', 'status', 'note'])]
class PurchasePlan extends Model
{
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    protected function casts(): array
    {
        return ['suggested_qty' => 'decimal:4', 'target_stock_qty' => 'decimal:4'];
    }
}
