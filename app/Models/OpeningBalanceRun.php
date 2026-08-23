<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningBalanceRun extends Model
{
    protected $fillable = [
        'kind', 'branch_id', 'as_of_date', 'line_count', 'total_amount',
        'source_name', 'source_checksum', 'posted_by', 'posted_at', 'notes',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'posted_at' => 'datetime',
        'total_amount' => 'decimal:4',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
