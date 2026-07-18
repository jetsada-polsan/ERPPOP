<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['batch_id', 'receipt_no', 'line_no', 'error_type', 'error_message', 'raw_data'])]
class ImportError extends Model
{
    const UPDATED_AT = null;

    public const DUPLICATE_FILE = 'DUPLICATE_FILE';

    public const DUPLICATE_RECEIPT = 'DUPLICATE_RECEIPT';

    public const PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';

    public const CUSTOMER_NOT_FOUND = 'CUSTOMER_NOT_FOUND';

    public const WAREHOUSE_NOT_FOUND = 'WAREHOUSE_NOT_FOUND';

    public const AMOUNT_NOT_MATCH = 'AMOUNT_NOT_MATCH';

    public const PAYMENT_NOT_MATCH = 'PAYMENT_NOT_MATCH';

    public const INVALID_DATE = 'INVALID_DATE';

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    protected function casts(): array
    {
        return ['raw_data' => 'array'];
    }
}
