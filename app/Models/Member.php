<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['member_code', 'name', 'phone', 'member_type_id', 'branch_id', 'points', 'is_active'])]
class Member extends Model
{
    const UPDATED_AT = null;

    public function memberType(): BelongsTo
    {
        return $this->belongsTo(MemberType::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function posReceipts(): HasMany
    {
        return $this->hasMany(PosReceipt::class);
    }

    protected function casts(): array
    {
        return [
            'points' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
