<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'build_uuid', 'version', 'channel', 'source_ref', 'status', 'requested_by',
    'github_run_id', 'github_run_url', 'commit_sha', 'failure_message',
    'dispatched_at', 'started_at', 'completed_at',
])]
class PosBuild extends Model
{
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'in_progress'], true);
    }

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
