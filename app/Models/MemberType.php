<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'point_rate'])]
class MemberType extends Model
{
    public $timestamps = false;

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    protected function casts(): array
    {
        return ['point_rate' => 'decimal:4'];
    }
}
