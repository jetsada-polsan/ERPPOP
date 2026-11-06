<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'code', 'name', 'hardware_profile', 'default_float', 'cash_warning_threshold', 'blind_count'])]
class PosTerminal extends Model
{
    public $timestamps = false;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PosReceipt::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(PosShift::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PosLog::class);
    }

    protected function casts(): array
    {
        return ['hardware_profile' => 'array', 'default_float' => 'decimal:2', 'cash_warning_threshold' => 'decimal:2', 'blind_count' => 'boolean'];
    }
}
