<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['batch_id', 'file_name', 'file_size', 'file_hash', 'raw_path', 'uploaded_at'])]
class ImportFile extends Model
{
    public $timestamps = false;

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }
}
