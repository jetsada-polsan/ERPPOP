<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ผลการตรวจรับสินค้าโอนย้ายด้วยสแกนบาร์โค้ด (1 ใบ ต่อ 1 ใบโอนย้าย/document_id)
 * เป็นชั้นบันทึกหลักฐานเพิ่มจากใบโอนย้ายเดิม ไม่แก้ไขสต๊อกเอง
 * ดู App\Services\Inventory\StockTransferReceiptService สำหรับตรรกะ/เหตุผลเชิงธุรกิจ
 */
#[Fillable(['document_id', 'status', 'created_by', 'confirmed_by', 'confirmed_at', 'note'])]
class StockTransferReceipt extends Model
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferReceiptItem::class);
    }

    public function isEditable(): bool
    {
        return $this->status === 'checking';
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }
}
