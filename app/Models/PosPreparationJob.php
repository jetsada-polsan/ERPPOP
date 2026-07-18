<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['job_no', 'job_type', 'branch_id', 'pos_terminal_id', 'from_date', 'to_date', 'status', 'note'])]
class PosPreparationJob extends Model
{
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function terminal(): BelongsTo { return $this->belongsTo(PosTerminal::class, 'pos_terminal_id'); }

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }
}
