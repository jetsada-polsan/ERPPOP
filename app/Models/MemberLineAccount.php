<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'line_user_id', 'linked_at', 'unlinked_at', 'is_active'])]
class MemberLineAccount extends Model
{
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'unlinked_at' => 'datetime', 'is_active' => 'boolean'];
    }
}
