<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['period', 'branch_id', 'file_name', 'file_hash', 'file_size', 'summary', 'exported_by', 'exported_at'])]
class AccountingExportRun extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['summary' => 'array', 'exported_at' => 'datetime'];
    }
}
