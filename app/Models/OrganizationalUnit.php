<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code','name','unit_type','parent_id','branch_id','manager_employee_id','description','sort_order','is_active'])]
class OrganizationalUnit extends Model
{
    protected function casts(): array { return ['is_active'=>'boolean']; }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name'); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function manager(): BelongsTo { return $this->belongsTo(Employee::class, 'manager_employee_id'); }
    public function assignments(): HasMany { return $this->hasMany(EmployeeOrgAssignment::class); }
    public function positions(): HasMany { return $this->hasMany(OrganizationPosition::class)->where('is_active', true)->orderBy('sort_order')->orderBy('title'); }
}
