<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'name', 'channel_type', 'target_name', 'token', 'notify_sales',
    'notify_qr_payment', 'notify_void_bill', 'notify_stock_alert', 'is_active',
])]
class LineIntegration extends Model
{
    protected function casts(): array
    {
        return [
            'notify_sales' => 'boolean',
            'notify_qr_payment' => 'boolean',
            'notify_void_bill' => 'boolean',
            'notify_stock_alert' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
