<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'code', 'name', 'branch_id', 'starts_date', 'ends_date',
    'start_time', 'end_time', 'days_of_week', 'note', 'is_active',
])]
class FlashSale extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FlashSaleItem::class);
    }

    // True when `now` falls within this campaign's date range, daily time
    // window (if set) and allowed days of week (if set).
    public function isRunningAt(Carbon $now): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($now->toDateString() < $this->starts_date->toDateString()) {
            return false;
        }

        if ($this->ends_date && $now->toDateString() > $this->ends_date->toDateString()) {
            return false;
        }

        if ($this->start_time && $now->format('H:i:s') < $this->start_time->format('H:i:s')) {
            return false;
        }

        if ($this->end_time && $now->format('H:i:s') > $this->end_time->format('H:i:s')) {
            return false;
        }

        if ($this->days_of_week) {
            $allowedDays = explode(',', $this->days_of_week);
            if (! in_array((string) $now->dayOfWeek, $allowedDays, true)) {
                return false;
            }
        }

        return true;
    }

    protected function casts(): array
    {
        return [
            'starts_date' => 'date',
            'ends_date' => 'date',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'is_active' => 'boolean',
        ];
    }
}
