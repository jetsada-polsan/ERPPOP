<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'document_type_id', 'prefix', 'is_default', 'is_active'])]
class DocumentBook extends Model
{
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    // เล่มเริ่มต้นของประเภทเอกสาร (ใช้เมื่อไม่ได้เลือกเล่ม)
    public static function defaultFor(string $typeCode): ?self
    {
        return static::whereHas('documentType', fn ($q) => $q->where('code', $typeCode))
            ->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('id')
            ->first();
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
