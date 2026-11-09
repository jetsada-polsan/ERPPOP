<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_shortage_status', 'stock_shortage_note', 'stock_shortage_approved_by', 'stock_shortage_approved_at', 'remainder_closed_at', 'remainder_closed_by',
    'document_id', 'salesman_id', 'sales_user_id', 'sales_area_id', 'status', 'confirmed_at', 'confirmed_document_id',
    'fulfillment_type', 'delivery_due_at', 'delivered_at', 'delivery_status', 'accounting_review_status',
    'accounting_reviewed_by', 'accounting_reviewed_at', 'accounting_review_note',
])]
class SaleBooking extends Model
{
    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONVERTED = 'converted_to_sale';

    public const STATUS_CANCELLED = 'cancelled';

    public const FULFILLMENT_PICKUP = 'pickup';

    public const FULFILLMENT_DELIVERY = 'delivery';

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_PARTIAL = 'partial';

    public const DELIVERY_DELIVERED = 'delivered';

    public const DELIVERY_CANCELLED = 'cancelled';

    /** ยังไม่ได้ส่งครบ = ยังต้องตามในรายงานครบกำหนดส่ง */
    public const DELIVERY_OUTSTANDING = [self::DELIVERY_PENDING, self::DELIVERY_PARTIAL];

    public function isDelivery(): bool
    {
        return $this->fulfillment_type === self::FULFILLMENT_DELIVERY;
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function salesUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function salesArea(): BelongsTo
    {
        return $this->belongsTo(SalesArea::class);
    }

    /** Includes the original single-document link for bookings created before tax splitting. */
    public function saleDocuments()
    {
        return Document::whereHas('documentType', fn ($query) => $query->where('code', DocumentType::CREDIT_SALE))
            ->where(fn ($query) => $query->where('source_document_id', $this->document_id)
                ->orWhere('id', $this->confirmed_document_id));
    }

    public function confirmedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'confirmed_document_id');
    }

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'delivery_due_at' => 'datetime',
            'delivered_at' => 'datetime',
            'accounting_reviewed_at' => 'datetime',
        ];
    }
}
