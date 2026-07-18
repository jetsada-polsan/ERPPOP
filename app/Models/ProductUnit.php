<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'qty_per_base_unit'])]
class ProductUnit extends Model
{
    private const CORRUPTED_NAMES = ['ǧ', 'ἧ', 'ἧ*10', 'ᾤ'];

    public $timestamps = false;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'base_unit_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'unit_id');
    }

    // ชื่อหน่วยสะอาด: ตัดตัวคูณที่ ETL จาก BPlus ฝังมาท้ายชื่อ (ลังx18 / กล่อง*12 -> ลัง / กล่อง)
    public function cleanName(): string
    {
        return trim(preg_replace('/\s*[x*×]\s*\d+(?:\.\d+)?$/u', '', (string) $this->name));
    }

    public function isCorrupted(): bool
    {
        return in_array(trim((string) $this->name), self::CORRUPTED_NAMES, true);
    }

    // จำนวนบรรจุจริง: ใช้ตัวคูณในชื่อก่อน (แม่นกว่า) ถ้าไม่มีค่อยใช้ qty_per_base_unit
    public function packSize(): float
    {
        if (preg_match('/[x*×]\s*(\d+(?:\.\d+)?)$/u', (string) $this->name, $m)) {
            return (float) $m[1];
        }

        return (float) ($this->qty_per_base_unit ?: 1);
    }

    // ป้ายแสดงผลอ่านง่าย: "ลัง (บรรจุ 18)" หรือ "ใบ" ถ้าบรรจุ 1
    public function displayLabel(): string
    {
        if ($this->isCorrupted()) {
            return sprintf('ข้อมูลเดิมเพี้ยน (รหัสหน่วย %s)', $this->code);
        }

        $pack = $this->packSize();

        return $pack > 1
            ? sprintf('%s (บรรจุ %s)', $this->cleanName(), rtrim(rtrim(number_format($pack, 2), '0'), '.'))
            : $this->cleanName();
    }

    protected function casts(): array
    {
        return ['qty_per_base_unit' => 'decimal:4'];
    }
}
