<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

#[Fillable(['branch_id', 'user_id', 'code', 'name', 'is_active', 'pos_pin_hash', 'must_change_pin', 'pin_changed_at'])]
class Salesman extends Model
{
    public $timestamps = false;

    protected $hidden = ['pos_pin_hash'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ตั้งโดยแอดมิน = ค่ายังไม่เป็นความลับ ต้องให้เจ้าตัวเปลี่ยนก่อนถึงจะอ้างอิงตัวตนได้ */
    public function setPin(string $pin, bool $mustChange): void
    {
        $this->forceFill([
            'pos_pin_hash' => Hash::make($pin),
            'must_change_pin' => $mustChange,
            'pin_changed_at' => $mustChange ? null : now(),
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'must_change_pin' => 'boolean',
            'pin_changed_at' => 'datetime',
        ];
    }
}
