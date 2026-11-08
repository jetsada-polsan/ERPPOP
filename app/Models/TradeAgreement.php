<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'vat_mode', 'prices_include_vat', 'payment_terms', 'credit_days', 'starts_on', 'ends_on', 'is_default', 'is_active'])]
class TradeAgreement extends Model
{
    public const VAT_MODES = ['standard' => 'อัตราทั่วไป', 'zero' => 'อัตราศูนย์', 'none' => 'ไม่มี VAT'];

    protected function casts(): array
    {
        return [
            'prices_include_vat' => 'boolean',
            'credit_days' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $date))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->orderByDesc('is_default')->orderBy('id');
    }

    public static function standardRate(string $date): string
    {
        return (string) (DB::table('vat_rates')
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->value('rate_percent') ?? 7);
    }

    public function fitsSaleBook(DocumentBook $book): bool
    {
        return (bool) $book->no_vat === ($this->vat_mode === 'none');
    }

    public function taxSnapshot(string $rate): array
    {
        return [
            'agreement_id' => $this->id,
            'agreement_name' => $this->name,
            'vat_mode' => $this->vat_mode,
            'vat_rate' => $this->vat_mode === 'standard' ? $rate : '0',
            'prices_include_vat' => $this->prices_include_vat,
            'payment_terms' => $this->payment_terms,
            'credit_days' => $this->credit_days,
        ];
    }
}
