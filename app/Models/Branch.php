<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name_th', 'name_en', 'is_active', 'default_warehouse_location_id', 'price_table_id'])]
class Branch extends Model
{
    public function defaultWarehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'default_warehouse_location_id');
    }

    /**
     * สาขาที่ยังไม่มีคลังเริ่มต้นใช้ออกเอกสารที่ต้องตัด/รับสต๊อกไม่ได้ - เช็คก่อนบันทึก
     * ทุกครั้ง แทนที่จะปล่อยให้ไปพังลึก ๆ ในชั้น service.
     */
    public function hasDefaultWarehouse(): bool
    {
        return $this->default_warehouse_location_id !== null;
    }

    public function priceTable(): BelongsTo
    {
        return $this->belongsTo(PriceTable::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function posTerminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
