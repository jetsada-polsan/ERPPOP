<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ทะเบียนรายงาน — ผู้บริหารเปิด/ปิดได้จากหน้าตั้งค่า การปิดคือซ่อนจากเมนูเท่านั้น
 * ไม่ลบ definition และไม่ลบประวัติที่เคยดึงไป
 */
class ReportDefinition extends Model
{
    protected $fillable = [
        'code', 'category', 'category_title', 'name', 'view_permission', 'owner_role',
        'frequency', 'priority', 'status', 'enabled', 'sort_order', 'description',
        'legacy_code', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** รายงานที่ยังไม่มีหน้าจอจริงต้องไม่โผล่ในเมนูแม้จะถูกเปิดไว้ */
    public function isRunnable(): bool
    {
        return $this->enabled && $this->status === 'available';
    }

    public function scopeRunnable(Builder $query): Builder
    {
        return $query->where('enabled', true)->where('status', 'available');
    }

    /** คีย์ที่หน้าเลือกรายงานใช้: code = "<category>.<report>" */
    public function reportKey(): string
    {
        return str_contains($this->code, '.') ? explode('.', $this->code, 2)[1] : $this->code;
    }
}
