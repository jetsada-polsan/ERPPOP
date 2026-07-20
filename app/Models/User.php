<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['branch_id', 'salesman_id', 'username', 'name', 'email', 'phone', 'position', 'password', 'is_active', 'must_change_password', 'mfa_secret', 'mfa_enabled_at', 'password_changed_at'])]
#[Hidden(['password', 'remember_token', 'mfa_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Branch this user is restricted to for data visibility (ยอดขาย/รายงาน).
     * Non-null = เห็นเฉพาะสาขาตัวเอง (แคชเชียร์/พนักงานสาขา).
     * Null = ส่วนกลาง/ผู้บริหาร เห็นทุกสาขา.
     */
    public function branchScopeId(): ?int
    {
        return $this->branch_id;
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** @var array<int, string>|null per-request cache of permission codes */
    private ?array $permissionCodes = null;

    /** @return array<int, string> */
    public function permissionCodes(): array
    {
        return $this->permissionCodes ??= $this->roles()->with('permissions')->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('code'))
            ->unique()->values()->all();
    }

    // สิทธิ์ที่ superadmin bypass ห้ามแตะ - ต้องถือจริงเท่านั้น (ควบคุมภายใน POS):
    // ขาย/ยกเลิกบิลหน้าร้าน ต้องเป็นแคชเชียร์/ผู้อนุมัติตัวจริง แม้แต่ GM ก็ทำแทนไม่ได้
    private const NON_BYPASS_PERMISSIONS = [
        'pos.sell', 'pos.void', 'pos.discount.override', 'pos.sell_below_cost', 'purchasing.approve',
        'stock.adjust.approve', 'inventory.quality.manage', 'inventory.cost.close',
        'stock.damage.approve', 'finance.note.approve',
        'management.view', 'budget.manage', 'payroll.manage', 'ecommerce.sync', 'monitoring.manage',
    ];

    public function hasPermission(string $code): bool
    {
        $codes = $this->permissionCodes();

        if (in_array($code, $codes, true)) {
            return true;
        }

        if (in_array($code, self::NON_BYPASS_PERMISSIONS, true)) {
            return false;
        }

        // System admins เข้าดู/ทดสอบทุกโมดูลได้ ยกเว้นสิทธิ์ควบคุมภายในข้างบน
        return in_array('users.manage', $codes, true)
            && in_array('settings.manage', $codes, true);
    }

    public function documentsCreated(): HasMany
    {
        return $this->hasMany(Document::class, 'created_by');
    }

    public function posReceipts(): HasMany
    {
        return $this->hasMany(PosReceipt::class, 'cashier_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }
}
