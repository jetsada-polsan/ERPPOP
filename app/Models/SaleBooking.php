<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_id', 'salesman_id', 'sales_area_id', 'status', 'confirmed_at', 'confirmed_document_id'])]
class SaleBooking extends Model
{
    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONVERTED = 'converted_to_sale';

    public const STATUS_CANCELLED = 'cancelled';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function confirmedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'confirmed_document_id');
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }
}
