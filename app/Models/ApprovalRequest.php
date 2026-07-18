<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['request_no', 'approval_type', 'subject', 'amount', 'status', 'requested_by', 'approved_by', 'note'])]
class ApprovalRequest extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}
