<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'name', 'area_type', 'branch_id', 'default_salesman_id', 'document_book_id', 'is_active'])]
class SalesArea extends Model
{
    public $timestamps = false;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function defaultSalesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'default_salesman_id');
    }

    public function documentBook(): BelongsTo
    {
        return $this->belongsTo(DocumentBook::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
