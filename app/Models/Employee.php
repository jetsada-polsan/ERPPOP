<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employee_code', 'full_name', 'nickname', 'gender', 'phone', 'alt_phone', 'national_id', 'nationality', 'birth_date_raw', 'address', 'branch_id', 'branch_text', 'department', 'position', 'employment_type', 'wage_type', 'wage_amount', 'start_date_raw', 'status', 'monthly_salary', 'social_security_enabled', 'remark', 'source_section', 'source_row', 'user_id'])]
class Employee extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orgAssignments(): HasMany
    {
        return $this->hasMany(EmployeeOrgAssignment::class);
    }

    public function maskedNationalId(): string
    {
        $id = preg_replace('/\D+/', '', (string) $this->national_id);

        return strlen($id) === 13 ? substr($id, 0, 1).'-xxxx-xxxxx-'.substr($id, -2, 2).'-x' : '-';
    }
}
