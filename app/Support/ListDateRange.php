<?php

namespace App\Support;

use ArrayAccess;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * ตัวช่วยจัดการช่วงวันที่สำหรับหน้ารายการเอกสาร
 *
 * กติกา:
 * 1. ไม่มี date_from/date_to ใน URL -> ค่าเริ่ม = วันนี้ถึงวันนี้ (timezone แอป)
 * 2. มี -> ใช้ค่าที่ส่งมา, to < from สลับให้, ช่วงเกิน 92 วัน -> ตัดเหลือ 92 วันนับจาก to พร้อมข้อความเตือน
 * 3. ค้น q ไม่จำกัดวัน เมื่อไม่ได้ส่งช่วงวันมาเอง (หาบิลเก่าได้) - ถ้าส่งช่วงวันมาเองให้ใช้ช่วงนั้น
 */
class ListDateRange implements ArrayAccess
{
    public const MAX_DAYS = 92;

    public function __construct(
        public ?string $from,
        public ?string $to,
        public bool $isDefault = false,
        public bool $isUnrestricted = false,
        public ?string $warning = null,
        public string $column = 'doc_date',
    ) {}

    public static function fromRequest(Request $request, string $column = 'doc_date', array $options = []): self
    {
        $hasFrom = $request->filled('date_from');
        $hasTo = $request->filled('date_to');
        $hasQ = $request->filled('q') && trim((string) $request->input('q')) !== '';

        // ถ้าไม่มี date_from และ date_to
        if (! $hasFrom && ! $hasTo) {
            // ค้นหา q ไม่จำกัดวัน เมื่อผู้ใช้ไม่ได้ส่งช่วงวันมาเอง
            if ($hasQ) {
                return new self(
                    from: null,
                    to: null,
                    isDefault: false,
                    isUnrestricted: true,
                    warning: null,
                    column: $column,
                );
            }

            // ค่าเริ่ม = วันนี้ถึงวันนี้ (timezone แอป)
            $today = now()->toDateString();

            return new self(
                from: $today,
                to: $today,
                isDefault: true,
                isUnrestricted: false,
                warning: null,
                column: $column,
            );
        }

        // กรณีมี date_from หรือ date_to
        $rawFrom = $request->input('date_from');
        $rawTo = $request->input('date_to');

        try {
            $cFrom = $hasFrom ? Carbon::parse($rawFrom)->startOfDay() : null;
        } catch (\Throwable) {
            $cFrom = null;
        }

        try {
            $cTo = $hasTo ? Carbon::parse($rawTo)->startOfDay() : null;
        } catch (\Throwable) {
            $cTo = null;
        }

        // จัดการกรณีส่งมาแค่วันเดียว
        if ($cFrom && ! $cTo) {
            $cTo = $cFrom->copy();
        } elseif (! $cFrom && $cTo) {
            $cFrom = $cTo->copy();
        } elseif (! $cFrom && ! $cTo) {
            // กรณี parse ไม่ผ่านทั้งคู่
            $today = now()->toDateString();

            return new self(
                from: $today,
                to: $today,
                isDefault: true,
                isUnrestricted: false,
                warning: null,
                column: $column,
            );
        }

        // to < from สลับให้
        if ($cTo->lt($cFrom)) {
            $tmp = $cFrom;
            $cFrom = $cTo;
            $cTo = $tmp;
        }

        $warning = null;
        // ช่วงเกิน 92 วัน -> ตัดเหลือ 92 วันนับจาก to
        $diffDays = $cFrom->diffInDays($cTo);
        if ($diffDays > self::MAX_DAYS) {
            $cFrom = $cTo->copy()->subDays(self::MAX_DAYS);
            $warning = 'ช่วงวันที่ถูกจำกัดไม่เกิน '.self::MAX_DAYS.' วัน (ระบบปรับวันเริ่มต้นเป็น '.$cFrom->format('d/m/Y').' อัตโนมัติ)';
        }

        return new self(
            from: $cFrom->toDateString(),
            to: $cTo->toDateString(),
            isDefault: false,
            isUnrestricted: false,
            warning: $warning,
            column: $column,
        );
    }

    /**
     * นำเงื่อนไขวันที่ไปผูกกับ Query Builder
     */
    public function apply(Builder|QueryBuilder $query, ?string $column = null): Builder|QueryBuilder
    {
        if ($this->isUnrestricted || ! $this->from || ! $this->to) {
            return $query;
        }

        $col = $column ?? $this->column;

        if ($this->from === $this->to) {
            return $query->whereDate($col, $this->from);
        }

        return $query->whereDate($col, '>=', $this->from)
            ->whereDate($col, '<=', $this->to);
    }

    /**
     * นำเงื่อนไขวันที่ไปผูกกับ Relation (เช่น document.doc_date)
     */
    public function applyRelation(Builder $query, string $relation = 'document', ?string $column = null): Builder
    {
        if ($this->isUnrestricted || ! $this->from || ! $this->to) {
            return $query;
        }

        $col = $column ?? $this->column;

        return $query->whereHas($relation, fn ($q) => $this->apply($q, $col));
    }

    /**
     * ข้อความป้ายแสดงช่วงวันที่
     */
    public function label(): string
    {
        if ($this->isUnrestricted) {
            return 'ทั้งหมด (ค้นหา)';
        }

        if (! $this->from || ! $this->to) {
            return 'ทุกช่วงเวลา';
        }

        $today = now()->toDateString();
        if ($this->isDefault && $this->from === $today && $this->to === $today) {
            return 'วันนี้ ('.Carbon::parse($this->from)->format('d/m/Y').')';
        }

        if ($this->from === $this->to) {
            return Carbon::parse($this->from)->format('d/m/Y');
        }

        return Carbon::parse($this->from)->format('d/m/Y').' - '.Carbon::parse($this->to)->format('d/m/Y');
    }

    // ArrayAccess methods สำหรับความเข้ากันได้
    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['from', 'to', 'isDefault', 'isUnrestricted', 'warning', 'column']);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'from' => $this->from,
            'to' => $this->to,
            'isDefault' => $this->isDefault,
            'isUnrestricted' => $this->isUnrestricted,
            'warning' => $this->warning,
            'column' => $this->column,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (property_exists($this, $offset)) {
            $this->{$offset} = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (property_exists($this, $offset)) {
            $this->{$offset} = null;
        }
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'isDefault' => $this->isDefault,
            'isUnrestricted' => $this->isUnrestricted,
            'warning' => $this->warning,
            'column' => $this->column,
        ];
    }
}
