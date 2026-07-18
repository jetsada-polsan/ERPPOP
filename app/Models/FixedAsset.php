<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'asset_code', 'name', 'category', 'branch_id', 'acquired_date', 'cost',
    'salvage_value', 'useful_life_months', 'accumulated_depreciation',
    'status', 'disposed_date', 'note',
])]
class FixedAsset extends Model
{
    public const STATUS_LABELS = [
        'active' => 'ใช้งาน',
        'fully_depreciated' => 'คิดค่าเสื่อมครบแล้ว',
        'disposed' => 'จำหน่าย/ตัดออก',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function depreciationRecords(): HasMany
    {
        return $this->hasMany(DepreciationRecord::class);
    }

    // ค่าเสื่อมต่อเดือน (เส้นตรง) = (ทุน - มูลค่าซาก) / อายุการใช้งาน (เดือน)
    public function monthlyDepreciation(): float
    {
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        return round(((float) $this->cost - (float) $this->salvage_value) / $this->useful_life_months, 2);
    }

    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
    }

    // มูลค่าที่คิดค่าเสื่อมได้ทั้งหมด (ทุน - ซาก) - กันคิดเกิน
    public function depreciableBase(): float
    {
        return round((float) $this->cost - (float) $this->salvage_value, 2);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    protected function casts(): array
    {
        return [
            'acquired_date' => 'date',
            'disposed_date' => 'date',
            'cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
        ];
    }
}
