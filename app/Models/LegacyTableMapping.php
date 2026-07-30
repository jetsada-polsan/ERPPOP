<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'legacy_database', 'legacy_schema', 'legacy_table', 'target_table', 'module',
    'mapping_type', 'status', 'legacy_column_count', 'target_column_count',
    'shared_column_count', 'notes', 'reviewed_by', 'reviewed_at',
])]
class LegacyTableMapping extends Model
{
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }
}
