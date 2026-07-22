<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['period', 'status', 'closed_by', 'closed_at'])]
class InventoryCostClosePeriod extends Model
{
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }
}
