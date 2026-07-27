<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Token ต่อเครื่องสำหรับ POS desktop (Tauri). device ล็อก "สาขา" ไว้ ส่วน "คนขาย"
 * มาจากการยืนยัน PIN ที่ /api/pos/cashier/login ซึ่งบันทึกไว้ที่ active_cashier_id
 * ทำให้ client ปลอม cashier_id ของคนอื่นในสาขาไม่ได้
 */
class PosDevice extends Model
{
    protected $fillable = [
        'name', 'user_id', 'branch_id', 'terminal_code',
        'token_hash', 'token_encrypted', 'last_seen_at', 'last_ip', 'revoked_at',
        'active_cashier_id', 'cashier_verified_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'cashier_verified_at' => 'datetime',
        'token_encrypted' => 'encrypted',
    ];

    protected $hidden = ['token_hash', 'token_encrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function activeCashier(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'active_cashier_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** บันทึกว่าใส่ PIN ผ่านแล้ว — ใช้ตอนแคชเชียร์ล็อกอินหรือสลับคนบนเครื่องเดิม */
    public function markCashierVerified(Salesman $cashier): void
    {
        $this->forceFill([
            'active_cashier_id' => $cashier->id,
            'cashier_verified_at' => now(),
        ])->saveQuietly();
    }

    /**
     * คนขายที่ยืนยัน PIN ไว้บนเครื่องนี้ และยังไม่หมดอายุ (null = ยังไม่ยืนยัน/หมดอายุ)
     * อายุเซสชันตั้งได้ที่ pos_cashier_session_hours, 0 = ไม่หมดอายุ
     */
    public function verifiedCashierId(): ?int
    {
        if (! $this->active_cashier_id || ! $this->cashier_verified_at) {
            return null;
        }

        $hours = (int) (AppSetting::get('pos_cashier_session_hours') ?? 16);
        if ($hours > 0 && $this->cashier_verified_at->lt(now()->subHours($hours))) {
            return null;
        }

        return (int) $this->active_cashier_id;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** สร้าง device + คืน [PosDevice, plaintextToken] — token โชว์ครั้งเดียวตอนสร้าง */
    public static function issue(array $attributes): array
    {
        $token = Str::random(48);
        $device = static::create($attributes + [
            'token_hash' => static::hashToken($token),
            'token_encrypted' => $token,
        ]);

        return [$device, $token];
    }

    public function rotateToken(): string
    {
        $token = Str::random(48);
        $this->update([
            'token_hash' => static::hashToken($token),
            'token_encrypted' => $token,
            'revoked_at' => null,
        ]);

        return $token;
    }
}
