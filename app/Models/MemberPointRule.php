<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'name', 'rule_type', 'baht_per_point', 'point_value_baht',
    'multiplier', 'starts_date', 'ends_date', 'is_active',
])]
class MemberPointRule extends Model
{
    public function scopeRunningToday(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_date')->orWhere('starts_date', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_date')->orWhere('ends_date', '>=', $today));
    }

    protected function casts(): array
    {
        return [
            'baht_per_point' => 'decimal:4',
            'point_value_baht' => 'decimal:4',
            'multiplier' => 'decimal:4',
            'starts_date' => 'date',
            'ends_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
