<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'document_id', 'direction', 'points', 'balance_after', 'note'])]
class MemberPointTransaction extends Model
{
    const UPDATED_AT = null;

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected function casts(): array
    {
        return [
            'points' => 'decimal:4',
            'balance_after' => 'decimal:4',
        ];
    }
}
