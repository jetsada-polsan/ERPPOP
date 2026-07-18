<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id','organizational_unit_id','position_title','is_primary','effective_from','effective_to'])]
class EmployeeOrgAssignment extends Model
{
    protected function casts(): array { return ['is_primary'=>'boolean','effective_from'=>'date','effective_to'=>'date']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function organizationalUnit(): BelongsTo { return $this->belongsTo(OrganizationalUnit::class); }
}
