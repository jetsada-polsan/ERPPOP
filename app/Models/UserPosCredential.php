<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

#[Fillable(['user_id', 'pin_hash', 'force_pin_change', 'pin_changed_at', 'credential_version', 'revoked_at'])]
class UserPosCredential extends Model
{
    protected $hidden = ['pin_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setPin(string $pin, bool $forceChange): void
    {
        $this->forceFill([
            'pin_hash' => Hash::make($pin),
            'force_pin_change' => $forceChange,
            'pin_changed_at' => $forceChange ? null : now(),
            'credential_version' => now(),
            'revoked_at' => null,
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'force_pin_change' => 'boolean',
            'pin_changed_at' => 'datetime',
            'credential_version' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
