<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['operation', 'status', 'message', 'details', 'run_by', 'started_at', 'finished_at'])]
class OperationRun extends Model
{
    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
