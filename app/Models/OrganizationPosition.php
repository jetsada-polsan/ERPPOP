<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organizational_unit_id','code','title','holder_employee_id','reports_to_position_id','sort_order','is_active'])]
class OrganizationPosition extends Model
{
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function unit(): BelongsTo { return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id'); }
    public function holder(): BelongsTo { return $this->belongsTo(Employee::class, 'holder_employee_id'); }
    public function reportsTo(): BelongsTo { return $this->belongsTo(self::class, 'reports_to_position_id'); }
    public function subordinates(): HasMany { return $this->hasMany(self::class, 'reports_to_position_id'); }
}
