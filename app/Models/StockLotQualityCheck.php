<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_lot_id', 'result', 'note', 'evidence_path', 'checked_by', 'checked_at'])]
class StockLotQualityCheck extends Model
{
    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }
}
