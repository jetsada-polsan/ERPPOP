<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemError extends Model
{
    use HasFactory;

    protected $table = 'system_errors';

    protected $fillable = [
        'occurred_at',
        'status',
        'url',
        'route_name',
        'user_id',
        'message',
        'exception_class',
        'file_line',
        'fingerprint',
        'count',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'status' => 'integer',
            'count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('occurred_at', '>=', now()->subHours($hours));
    }

    public function scopeRecentDays(Builder $query, int $days = 7): Builder
    {
        return $query->where('occurred_at', '>=', now()->subDays($days));
    }

    public function markResolved(): bool
    {
        return $this->update(['resolved_at' => now()]);
    }
}
