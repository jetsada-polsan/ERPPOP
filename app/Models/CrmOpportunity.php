<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'branch_id', 'sales_user_id', 'sales_area_id', 'title', 'stage', 'expected_amount', 'expected_close_date', 'note', 'lost_reason'])]
class CrmOpportunity extends Model
{
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function salesUser(): BelongsTo { return $this->belongsTo(User::class, 'sales_user_id'); }
    public function salesArea(): BelongsTo { return $this->belongsTo(SalesArea::class); }

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:4',
            'expected_close_date' => 'date',
        ];
    }
}
