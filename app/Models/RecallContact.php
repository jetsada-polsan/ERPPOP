<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recall_case_id', 'document_id', 'customer_id', 'branch_id', 'qty', 'contact_status', 'contact_note', 'contacted_by', 'contacted_at'])]
class RecallContact extends Model
{
    public function recallCase(): BelongsTo
    {
        return $this->belongsTo(RecallCase::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'contacted_at' => 'datetime'];
    }
}
