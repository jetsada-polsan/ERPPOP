<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['case_no', 'stock_lot_id', 'severity', 'status', 'reason', 'opened_by', 'opened_at', 'closed_by', 'closed_at'])]
class RecallCase extends Model
{
    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(RecallContact::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
