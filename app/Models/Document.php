<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'document_type_id', 'document_book_id', 'branch_id', 'doc_number', 'doc_date', 'salesman_id', 'sales_area_id',
    'customer_id', 'supplier_id', 'reference', 'status', 'total_items', 'total_amount',
    'remark', 'created_by',
])]
class Document extends Model
{
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function documentBook(): BelongsTo
    {
        return $this->belongsTo(DocumentBook::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function salesArea(): BelongsTo
    {
        return $this->belongsTo(SalesArea::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockDocument(): HasOne
    {
        return $this->hasOne(StockDocument::class);
    }

    public function saleBooking(): HasOne
    {
        return $this->hasOne(SaleBooking::class);
    }

    public function openItem(): HasOne
    {
        return $this->hasOne(CustomerOpenItem::class);
    }

    public function paymentDocument(): HasOne
    {
        return $this->hasOne(PaymentDocument::class);
    }

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'total_amount' => 'decimal:4',
            'cancelled_at' => 'datetime',
        ];
    }
}
