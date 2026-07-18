<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'pos_code', 'pos_terminal_id', 'source_system', 'sale_date', 'source_zip_name', 'source_cds_name',
    'file_hash', 'record_count', 'status', 'uploaded_by', 'uploaded_at',
    'validated_at', 'confirmed_by', 'confirmed_at', 'posted_at',
])]
class ImportBatch extends Model
{
    // Lifecycle: uploaded -> parsed -> validated|has_error -> confirmed -> posted ; or cancelled
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PARSED = 'parsed';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_HAS_ERROR = 'has_error';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_POSTED = 'posted';

    public const STATUS_POSTED_WITH_ERRORS = 'posted_with_errors';

    public const STATUS_CANCELLED = 'cancelled';

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ImportFile::class, 'batch_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ImportedReceipt::class, 'batch_id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class, 'batch_id');
    }

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'uploaded_at' => 'datetime',
            'validated_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }
}
