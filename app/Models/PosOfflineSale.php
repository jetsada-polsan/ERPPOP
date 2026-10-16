<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosOfflineSale extends Model
{
    public const PROCESSING = 'processing';

    public const POSTED = 'posted';

    public const NEEDS_REVIEW = 'needs_review';

    public const RESOLVED = 'resolved';

    protected $fillable = [
        'offline_uuid', 'user_id', 'branch_id', 'shift_id', 'sold_at', 'offline_total', 'status',
        'pos_receipt_id', 'receipt_no', 'review_reason', 'payload', 'attempts', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sold_at' => 'datetime',
            'resolved_at' => 'datetime',
            'offline_total' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
