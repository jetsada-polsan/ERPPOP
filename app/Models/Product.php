<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'sku_code', 'legacy_sku', 'name_th', 'name_en', 'note', 'product_category_id', 'product_department_id',
    'product_brand_id', 'base_unit_id', 'default_price', 'maximum_sale_price',
    'minimum_margin_percent', 'margin_control_policy', 'average_cost', 'last_purchase_cost',
    'last_purchase_cost_at', 'is_vat', 'tracks_expiry', 'shelf_life_days', 'expiry_warning_days',
    'clearance_warning_days', 'clearance_discount_percent', 'expiry_sale_policy', 'is_active',
    'negative_stock_policy', 'reorder_point', 'minimum_stock', 'maximum_stock',
])]
class Product extends Model
{
    use SoftDeletes;

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'product_brand_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ProductDepartment::class, 'product_department_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'base_unit_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(PriceChange::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function supplierPriceSchedules(): HasMany
    {
        return $this->hasMany(SupplierPriceSchedule::class);
    }

    public function posPriceSchedules(): HasMany
    {
        return $this->hasMany(PosPriceSchedule::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_vat' => 'boolean',
            'tracks_expiry' => 'boolean',
            'expiry_warning_days' => 'integer',
            'shelf_life_days' => 'integer',
            'clearance_warning_days' => 'integer',
            'clearance_discount_percent' => 'decimal:2',
            'default_price' => 'decimal:8',
            'maximum_sale_price' => 'decimal:8',
            'minimum_margin_percent' => 'decimal:8',
            'average_cost' => 'decimal:8',
            'last_purchase_cost' => 'decimal:8',
            'last_purchase_cost_at' => 'datetime',
            'reorder_point' => 'decimal:8',
            'minimum_stock' => 'decimal:8',
            'maximum_stock' => 'decimal:8',
        ];
    }
}
