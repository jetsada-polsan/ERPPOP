<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'name', 'device_type', 'branch_id', 'ip_address', 'status', 'note'])]
class ShowPriceDevice extends Model
{
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
}
