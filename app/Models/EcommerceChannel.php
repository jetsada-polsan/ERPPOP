<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'name', 'platform', 'shop_name', 'sync_status', 'last_synced_at',
    'credential_note', 'is_active',
])]
class EcommerceChannel extends Model
{
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
