<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['billing_note_id', 'customer_open_item_id', 'balance_at_billing'])]
class BillingNoteItem extends Model
{
    public $timestamps = false;

    public function billingNote(): BelongsTo
    {
        return $this->belongsTo(BillingNote::class);
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(CustomerOpenItem::class, 'customer_open_item_id');
    }

    protected function casts(): array
    {
        return ['balance_at_billing' => 'decimal:2'];
    }
}
