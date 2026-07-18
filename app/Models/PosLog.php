<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pos_terminal_id', 'station', 'function_code', 'log_data', 'logged_at'])]
class PosLog extends Model
{
    public $timestamps = false;

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    protected function casts(): array
    {
        return ['logged_at' => 'datetime'];
    }
}
