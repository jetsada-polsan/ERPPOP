<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'code', 'name', 'is_active', 'pos_pin_hash'])]
class Salesman extends Model
{
    public $timestamps = false;

    protected $hidden = ['pos_pin_hash'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
