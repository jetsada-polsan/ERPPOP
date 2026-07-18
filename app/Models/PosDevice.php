<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Token ต่อเครื่องสำหรับ POS desktop (Tauri). แต่ละ device ยิง API ในชื่อ
 * cashier user 1 คน — สาขา/รหัสพนักงานถูกล็อกผ่าน user เดิม (users.branch_id/salesman_id)
 * จึงบังคับใช้กฎ "ขายในชื่อ+สาขาตัวเอง" อัตโนมัติ ไม่ต้องแก้ logic checkout
 */
class PosDevice extends Model
{
    protected $fillable = [
        'name', 'user_id', 'branch_id', 'terminal_code',
        'token_hash', 'token_encrypted', 'last_seen_at', 'last_ip', 'revoked_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
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

    public function isActive(): bool
    {
        return $this->revoked_at === null;
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
